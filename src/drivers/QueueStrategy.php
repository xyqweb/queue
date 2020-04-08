<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-03
 * Time: 18:55
 */

namespace xyqWeb\queue\drivers;


abstract class QueueStrategy
{
    /**
     * @var array 队列配置参数
     */
    protected $config = [];

    /**
     * 推送队列
     *
     * @author xyq
     * @param $queue
     * @param null $priority
     * @return string
     */
    abstract public function push($queue, $priority = null);

    /**
     * 关闭连接
     *
     * @author xyq
     */
    abstract public function close();
}