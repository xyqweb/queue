<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-03
 * Time: 18:46
 */

namespace xyqWeb\queue;


use xyqWeb\queue\drivers\ConfigException;

class Queue
{
    /**
     * @var $_instance
     */
    private static $_instance = null;
    /**
     * @var null|\xyqWeb\queue\drivers\RabbitMq
     */
    private static $driver = null;

    /**
     * 初始化Rpc
     *
     * @author xyq
     * @param string $strategy
     * @param array $config
     * @throws ConfigException
     */
    public static function initMq(string $strategy, array $config = [])
    {
        if (!in_array($strategy, ['rabbitMq'])) {
            throw new ConfigException('queue strategy error,only accept rabbitMq');
        }
        $class = "\\xyqWeb\\queue\\drivers\\" . ucfirst($strategy);
        self::$driver = new $class($config);
    }

    /**
     * 单例入口
     *
     * @author xyq
     * @return Queue
     */

    public static function init() : Queue
    {
        if (is_null(self::$_instance) || !self::$_instance instanceof Queue) {
            self::$_instance = new static();
        }
        return self::$_instance;
    }

    /**
     * 更换队列名称
     *
     * @author xyq
     * @param string $queueName
     * @return $this
     */
    public function queueName(string $queueName)
    {
        self::$driver->queueName($queueName);
        return $this;
    }

    /**
     * 设置延迟时间
     *
     * @author xyq
     * @param int $delay
     * @return $this
     */
    public function delay(int $delay)
    {
        self::$driver->delay($delay);
        return $this;
    }

    /**
     * 推送队列
     *
     * @author xyq
     * @param $payload
     * @param null $priority
     * @return string
     * @throws \Exception
     */
    public function push($payload, $priority = null) : string
    {
        return self::$driver->push($payload, $priority);
    }

    /**
     * 关闭连接
     *
     * @author xyq
     */
    public function close()
    {
        self::$driver->close();
    }
}