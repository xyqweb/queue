<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-08
 * Time: 15:02
 */

namespace xyqWeb\queue;


interface JobInterface
{
    /**
     * @param Queue $queue which pushed and is handling the job
     */
    public function execute($queue);
}