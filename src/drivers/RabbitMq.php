<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-03
 * Time: 18:51
 */

namespace xyqWeb\queue\drivers;

use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Enqueue\AmqpLib\AmqpContext;
use Enqueue\AmqpTools\DelayStrategyAware;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use xyqWeb\queue\JobInterface;
use xyqWeb\queue\library\Message;
use xyqWeb\queue\library\Serializer;

class RabbitMq extends QueueStrategy
{
    const ATTEMPT = 'yii-attempt';
    const TTR = 'yii-ttr';
    const DELAY = 'yii-delay';
    const PRIORITY = 'yii-priority';
    /**
     * @var int 延迟时间
     */
    private $delayTime = 0;
    /**
     * @var string 队列名称
     */
    private $queueName = 'queue';
    /**
     * The time PHP socket waits for an information while reading. In seconds.
     *
     * @var float|null
     */
    public $readTimeout;
    /**
     * The time PHP socket waits for an information while witting. In seconds.
     *
     * @var float|null
     */
    public $writeTimeout;
    /**
     * The time RabbitMQ keeps the connection on idle. In seconds.
     *
     * @var float|null
     */
    public $connectionTimeout;
    /**
     * The periods of time PHP pings the broker in order to prolong the connection timeout. In seconds.
     *
     * @var float|null
     */
    public $heartbeat = 5;
    /**
     * PHP uses one shared connection if set true.
     *
     * @var bool|null
     */
    public $persisted;
    /**
     * The connection will be established as later as possible if set true.
     *
     * @var bool|null
     */
    public $lazy;
    /**
     * If false prefetch_count option applied separately to each new consumer on the channel
     * If true prefetch_count option shared across all consumers on the channel.
     *
     * @var bool|null
     */
    public $qosGlobal;
    /**
     * Defines number of message pre-fetched in advance on a channel basis.
     *
     * @var int|null
     */
    public $qosPrefetchSize;
    /**
     * Defines number of message pre-fetched in advance per consumer.
     *
     * @var int|null
     */
    public $qosPrefetchCount;
    /**
     * Amqp interop context.
     *
     * @var AmqpContext
     */
    protected $context;
    /**
     * Amqp interop context.
     *
     * @var AmqpContext
     */
    protected $pushContext;
    /**
     * 消费失败后，间隔60秒后才可再次被消费
     * @var integer
     */
    public $reconsumeTime = 60;
    /**
     * The property tells whether the setupBroker method was called or not.
     * Having it we can do broker setup only once per process.
     *
     * @var bool
     */
    protected $setupBrokerDone = false;
    /**
     * The property tells whether the setupBroker method was called or not.
     * Having it we can do broker setup only once per process.
     *
     * @var bool
     */
    protected $pushSetupBrokerDone = false;
    /**
     * 错误mq队列
     * @var string
     */
    public $errorQueueName = NULL;

    /**
     * 错误mq 路由
     * @var string
     */
    public $errorRoutingKey = NULL;
    /**
     * This property should be an integer indicating the maximum priority the queue should support. Default is 10.
     *
     * @var int
     */
    public $maxPriority = 10;
    /**
     * retry times defaul max 3.
     *
     * @var int
     */
    public $maxFailNum = 3;
    /**
     * 路由key
     * @var string
     */
    public $routingKey = NULL;
    /**
     * The exchange used to publish messages to.
     *
     * @var string
     */
    public $exchangeName = 'exchange';
    /**
     * @var string
     */
    public $classKey = 'class';
    /**
     * @var object 日志组件
     */
    protected $logDriver;
    /**
     * @var object 日志组件
     */
    protected $serialize;

    /**
     * RabbitMq constructor.
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        if (isset($config['queueName']) && is_string($config['queueName']) && !empty($config['queueName'])) {
            $this->queueName = $config['queueName'];
        }
        if (isset($config['logDriver']) && is_object($config['logDriver'])) {
            $this->logDriver = $config['logDriver'];
        }
        if (isset($config['heartbeat']) && is_numeric($config['heartbeat'])) {
            $this->heartbeat = $config['heartbeat'];
        }
        $this->config = $config;
        $this->serialize = new Serializer();
    }

    /**
     * 打开RabbitMQ的连接
     *
     * @author xyq
     * @param string $type
     */
    private function open($type = 'consumer')
    {
        if ('consumer' == $type) {
            if ($this->context) {
                return;
            }
        } else {
            if ($this->pushContext) {
                return;
            }
        }
        $connectionClass = AmqpLibConnectionFactory::class;

        $config = [
            'host'               => $this->config['host'],
            'port'               => $this->config['port'],
            'user'               => $this->config['user'],
            'pass'               => $this->config['password'],
            'vhost'              => $this->config['vhost'],
            'read_timeout'       => $this->readTimeout,
            'write_timeout'      => $this->writeTimeout,
            'connection_timeout' => $this->connectionTimeout,
            'heartbeat'          => floatval($this->heartbeat),
            'persisted'          => $this->persisted,
            'lazy'               => $this->lazy,
            'qos_global'         => $this->qosGlobal,
            'qos_prefetch_size'  => $this->qosPrefetchSize,
            'qos_prefetch_count' => $this->qosPrefetchCount,
        ];
        $config = array_filter($config, function ($value) {
            return null !== $value;
        });

        /** @var AmqpLibConnectionFactory $factory */
        $factory = new $connectionClass($config);
        if ('consumer' == $type) {
            $this->context = $factory->createContext();
            if ($this->context instanceof DelayStrategyAware) {
                $this->context->setDelayStrategy(new RabbitMqDlxDelayStrategy());
            }
        } else {
            $this->pushContext = $factory->createContext();
            if ($this->pushContext instanceof DelayStrategyAware) {
                $this->pushContext->setDelayStrategy(new RabbitMqDlxDelayStrategy());
            }
        }
    }

    /**
     * 设置broker
     *
     * @author xyq
     * @param string $type
     * @throws \Interop\Queue\Exception\Exception
     */
    protected function setupBroker($type = 'consumer')
    {
        if ('consumer' == $type) {
            if ($this->setupBrokerDone) {
                return;
            }

            $queue = $this->context->createQueue($this->queueName);
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
            $queueArguments = ['x-max-priority' => $this->maxPriority];
            if (isset($this->config['queueArguments']) && is_array($this->config['queueArguments']) && !empty($this->config['queueArguments'])) {
                $queueArguments = array_merge($queueArguments, $this->config['queueArguments']);
            }
            $queue->setArguments($queueArguments);
            $this->context->declareQueue($queue);

            $topic = $this->context->createTopic($this->exchangeName);
            $topic->setType(AmqpTopic::TYPE_DIRECT);
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
            $this->context->declareTopic($topic);

            $this->context->bind(new AmqpBind($queue, $topic, $this->routingKey));
            $this->setupBrokerDone = true;
        } else {
            if ($this->pushSetupBrokerDone) {
                return;
            }
            $queue = $this->pushContext->createQueue($this->queueName);
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
            $queueArguments = ['x-max-priority' => $this->maxPriority];
            if (isset($this->config['queueArguments']) && is_array($this->config['queueArguments']) && !empty($this->config['queueArguments'])) {
                $queueArguments = array_merge($queueArguments, $this->config['queueArguments']);
            }
            $queue->setArguments($queueArguments);
            $this->pushContext->declareQueue($queue);

            $topic = $this->pushContext->createTopic($this->exchangeName);
            $topic->setType(AmqpTopic::TYPE_DIRECT);
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
            $this->pushContext->declareTopic($topic);

            $this->pushContext->bind(new AmqpBind($queue, $topic, $this->routingKey));
            $this->pushSetupBrokerDone = true;
        }
    }

    /**
     * 监听队列
     *
     * @author xyq
     */
    public function listen()
    {
        $listenQueue = $listenRoutingKey = $listenErrorQueueName = $listenErrorRoutingKey = '';
        while (true) {
            try {
                $this->initParams();
                if (empty($listenQueue)) {
                    $listenQueue = $this->queueName;
                    $listenRoutingKey = $this->routingKey;
                    $listenErrorQueueName = $this->errorQueueName;
                    $listenErrorRoutingKey = $this->errorRoutingKey;
                } elseif ($listenQueue !== $this->queueName) {
                    //此处的目的主要是防止当前队列正在监听中时推入其他队列名称导致监听队列名偏移
                    $this->queueName = $listenQueue;
                    $this->routingKey = $listenRoutingKey;
                    $this->errorQueueName = $listenErrorQueueName;
                    $this->errorRoutingKey = $listenErrorRoutingKey;
                    $this->setupBrokerDone = false;
                }
                $this->open('consumer');
                $this->setupBroker('consumer');

                $queue = $this->context->createQueue($this->queueName);
                $consumer = $this->context->createConsumer($queue);
                $consumerFun = $this->context->createSubscriptionConsumer();
                Message::$serialize = $this->serialize;
                Message::$logDriver = $this->logDriver;
                $consumerFun->subscribe($consumer, function (AmqpMessage $message, AmqpConsumer $consumer) {
                    $ttr = $message->getProperty(self::TTR);
                    $attempt = $message->getProperty(self::ATTEMPT, 1);
                    $messageId = $message->getMessageId();
                    if (is_object($this->logDriver) && method_exists($this->logDriver, 'write')) {
                        $this->logDriver->write('queue/queue_consumer.log', ' messageId:' . $message->getMessageId() . ' payload:' . $message->getBody());
                    }
                    if (Message::handleMessage($messageId, $message->getBody(), $ttr, $attempt)) {
                        $consumer->acknowledge($message);
                    } else {
                        $consumer->acknowledge($message);
                        $this->push($this->serialize->unSerialize($message->getBody()), $ttr, 60, null, $attempt);
                    }
                    return true;
                });

                $consumerFun->consume();
            } catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
                echo "AMQPRuntimeException " . $e->getMessage() . PHP_EOL;
                $this->close();
                sleep(1);
            } catch (\TypeError $e) {
                echo "Exception " . $e->getMessage() . PHP_EOL;
                $this->close();
                sleep(2);
            } catch (\Exception $e) {
                echo "Exception " . $e->getMessage() . PHP_EOL;
                $this->close();
                sleep(2);
            } catch (\Throwable $e) {
                echo "Throwable Exception " . $e->getMessage() . PHP_EOL;
                $this->close();
                sleep(5);
            }
        }
    }

    /**
     * 延迟时间
     *
     * @author xyq
     * @param int $time
     * @return $this
     */
    public function delay(int $time = 0)
    {
        $this->delayTime = $time;
        return $this;
    }

    /**
     * 设置队列名称
     *
     * @author xyq
     * @param string $queueName
     * @return $this
     */
    public function queueName(string $queueName)
    {
        if ($queueName != $this->queueName) {
            $this->pushSetupBrokerDone = false;
            $this->delay(0);
        }
        $this->queueName = $queueName;
        $this->routingKey = $queueName . 'Key';
        $this->errorQueueName = $this->queueName . 'Error';
        $this->errorRoutingKey = $this->queueName . 'ErrorKey';
        return $this;
    }

    /**
     * 推送队列
     *
     * @author xyq
     * @param $payload
     * @param null $ttr
     * @param null $delay
     * @param null $priority
     * @param null $attempt
     * @return string|null
     * @throws \Exception
     */
    public function push($payload, $ttr = null, $delay = null, $priority = null, $attempt = null)
    {
        $this->initParams();
        $time = 0;
        $messageId = $e = '';
        do {
            try {
                $messageId = $this->pushMessage($payload, $ttr, $delay, $priority, $attempt);
            } catch (\TypeError $e) {
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
            if (!empty($e)) {
                $this->closePush();
                $time++;
            }
        } while ($time === 1);
        if (empty($messageId)) {
            throw new \Exception('队列推送失败：' . $e->getMessage());
        }
        return $messageId;
    }

    /**
     * 执行消息入队
     *
     * @author xyq
     * @param $payload
     * @param null $ttr
     * @param null $delay
     * @param null $priority
     * @param null $attempt
     * @return string|null
     * @throws ConfigException
     * @throws \Interop\Queue\Exception
     * @throws \Interop\Queue\Exception\DeliveryDelayNotSupportedException
     * @throws \Interop\Queue\Exception\Exception
     * @throws \Interop\Queue\Exception\InvalidDestinationException
     * @throws \Interop\Queue\Exception\InvalidMessageException
     * @throws \Interop\Queue\Exception\PriorityNotSupportedException
     */
    private function pushMessage($payload, $ttr = null, $delay = null, $priority = null, $attempt = null)
    {
        if (!($payload instanceof JobInterface)) {
            throw new ConfigException("Job must be instance of JobInterface.");
        }
        $payload = $this->serialize->serialize($payload);
        $tempQueueName = $this->queueName;
        $tempRoutingKey = $this->routingKey;
        if (is_int($attempt)) {
            $attempt++;
            if ($attempt > $this->maxFailNum) {
                $this->delayTime = $delay = null;
                $this->queueName = $this->errorQueueName;
                $this->routingKey = $this->errorRoutingKey;
                $this->setupBrokerDone = false;
            }
        } else {
            $attempt = 1;
        }
        $this->open('push');
        $this->setupBroker('push');
        $topic = $this->pushContext->createTopic($this->exchangeName);
        $message = $this->pushContext->createMessage($payload);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
        $message->setMessageId(uniqid('', true));
        $message->setTimestamp(time());
        $message->setProperty(self::ATTEMPT, $attempt);
        $message->setProperty(self::TTR, is_int($ttr) && $ttr > 0 ? $ttr : $this->config['ttr']);
        $message->setRoutingKey($this->routingKey);
        $producer = $this->pushContext->createProducer();
        $delay = is_int($delay) && $delay > 0 ? $delay : $this->delayTime;
        if ($delay) {
            $message->setProperty(self::DELAY, $delay);
            $producer->setDeliveryDelay($delay * 1000);
        }
        if ($priority) {
            $message->setProperty(self::PRIORITY, $priority);
            $producer->setPriority($priority);
        }
        $producer->send($topic, $message);
        $messageId = $message->getMessageId();
        if (is_object($this->logDriver) && method_exists($this->logDriver, 'write')) {
            $this->logDriver->write('queue/queue_push.log', ' messageId:' . $messageId . ' queueName:' . $this->queueName . ' payload:' . $payload);
        }
        if ($tempQueueName !== $this->queueName) {
            $this->queueName = $tempQueueName;
            $this->routingKey = $tempRoutingKey;
            $this->setupBrokerDone = false;
        }
        $this->delayTime = 0;
        return $messageId;
    }

    /**
     * Closes connection and channel.
     */
    public function closePush()
    {
        if (!$this->pushContext) {
            return;
        }
        try {
            $this->pushContext->close();
        } catch (\TypeError $e) {
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        $this->pushContext = null;
        $this->pushSetupBrokerDone = false;
    }

    public function close()
    {
        if (!$this->context) {
            return;
        }
        try {
            $this->context->close();
        } catch (\TypeError $e) {
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        $this->context = null;
        $this->context = false;
    }

    /**
     * 初始化errorKey及routingKey参数
     *
     * @author xyq
     */
    protected function initParams()
    {
        $this->routingKey = $this->routingKey ?? $this->queueName . 'Key';
        $this->errorQueueName = $this->errorQueueName ?? $this->queueName . 'Error';
        $this->errorRoutingKey = $this->errorRoutingKey ?? $this->queueName . 'ErrorKey';
    }
}
