<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-08
 * Time: 15:02
 */

namespace xyqWeb\queue;


abstract class JobInterface extends QueueObject
{
    /**
     * @param Queue $queue which pushed and is handling the job
     */
    abstract public function execute($queue);
}