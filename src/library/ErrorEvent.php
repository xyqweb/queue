<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-10
 * Time: 17:24
 */

namespace xyqWeb\queue\library;


class ErrorEvent extends JobEvent
{
    /**
     * @var \Exception|\Throwable
     */
    public $error;
    /**
     * @var bool
     */
    public $retry;
}