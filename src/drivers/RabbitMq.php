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
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use PhpAmqpLib\Message\AMQPMessage;
use xyqWeb\queue\JobInterface;

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
    public $heartbeat;
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
        $this->config = $config;
    }

    /**
     * 打开RabbitMQ的连接
     *
     * @author xyq
     */
    private function open()
    {
        if ($this->context) {
            return;
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
            'heartbeat'          => $this->heartbeat,
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

        $this->context = $factory->createContext();

        if ($this->context instanceof DelayStrategyAware) {
            $this->context->setDelayStrategy(new RabbitMqDlxDelayStrategy());
        }
    }

    /**
     * 设置broker
     *
     * @author xyq
     * @throws \Interop\Queue\Exception\Exception
     */
    protected function setupBroker()
    {
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
        $this->queueName = $queueName;
        $this->routingKey = $queueName . 'Key';
        return $this;
    }

    /**
     * @param mixed $data
     * @return array|mixed
     * @throws ConfigException
     */
    protected function toArray($data)
    {
        if (is_object($data)) {
            $result = [$this->classKey => get_class($data)];
            foreach (get_object_vars($data) as $property => $value) {
                if ($property === $this->classKey) {
                    throw new ConfigException("Object cannot contain $this->classKey property.");
                }
                $result[$property] = $this->toArray($value);
            }

            return $result;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if ($key === $this->classKey) {
                    throw new ConfigException("Array cannot contain $this->classKey key.");
                }
                $result[$key] = $this->toArray($value);
            }

            return $result;
        }

        return $data;
    }

    /**
     * 推送队列
     *
     * @author xyq
     * @param $payload
     * @param null $priority
     * @return string|null
     * @throws \Exception
     */
    public function push($payload, $priority = null)
    {
        try {
            if (!($payload instanceof JobInterface)) {
                throw new ConfigException("Job must be instance of JobInterface.");
            }
            $payload = json_encode($this->toArray($payload));
            $this->open();
            $this->setupBroker();
            $topic = $this->context->createTopic($this->exchangeName);
            $message = $this->context->createMessage($payload);
            $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
            $message->setMessageId(uniqid('', true));
            $message->setTimestamp(time());
            $message->setProperty(self::ATTEMPT, 1);
            $message->setProperty(self::TTR, $this->config['ttr']);
            $message->setRoutingKey($this->routingKey);
            $producer = $this->context->createProducer();
            if ($this->delayTime) {
                $message->setProperty(self::DELAY, $this->delayTime);
                $producer->setDeliveryDelay($this->delayTime * 1000);
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
            $this->delayTime = 0;
            return $messageId;
        } catch (\Throwable $e) {
            throw new \Exception('队列推送失败:' . $e->getMessage());
        }
    }

    /**
     * Closes connection and channel.
     */
    public function close()
    {
        if (!$this->context) {
            return;
        }

        $this->context->close();
        $this->context = null;
        $this->setupBrokerDone = false;
    }
}