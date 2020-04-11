<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-10
 * Time: 17:22
 */

namespace xyqWeb\queue\library;


class JobEvent extends Event
{
    /**
     * @var Queue
     * @inheritdoc
     */
    public $sender;
    /**
     * @var string|null unique id of a job
     */
    public $id;
    /**
     * @var JobInterface
     */
    public $job;
    /**
     * @var int time to reserve in seconds of the job
     */
    public $ttr;
    /**
     * @var int attempt number
     */
    public $attempt;
}