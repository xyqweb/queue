<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-04-10
 * Time: 22:21
 */

namespace xyqWeb\queue\library;


use InvalidArgumentException;
use xyqWeb\queue\JobInterface;

class Message
{
    /**
     * @var Serializer
     */
    static $serialize;

    /**
     * The exit code of the exec action which is returned when job was done.
     */
    const EXEC_DONE = 0;
    /**
     * The exit code of the exec action which is returned when job wasn't done and wanted next attempt.
     */
    const EXEC_RETRY = 3;
    /**
     * @var
     */
    static $logDriver;

    /**
     * @inheritdoc
     */
    public static function handleMessage($id, $message, $ttr, $attempt)
    {
        $job = self::$serialize->unSerialize($message);
        if (!($job instanceof JobInterface)) {
            throw new InvalidArgumentException("Job must be instance instead of JobInterface");
        }
        $event = new JobEvent([
            'id'      => $id,
            'job'     => $job,
            'ttr'     => $ttr,
            'attempt' => $attempt,
        ]);
        if ($event->handled) {
            return true;
        }
        $result = true;
        $error = '';
        try {
            if (method_exists($event->job, 'setMessageId')) {
                $event->job->setMessageId($id);
            }
            $res = $event->job->execute($job);
            ($res === false) ? $result = false : true;
        } catch (\Exception $error) {
            $result = false;
        } catch (\Throwable $error) {
            $result = false;
        }
        if (!$result) {
            if (is_object(self::$logDriver) && method_exists(self::$logDriver, 'write')) {
                $errorMessage = 'messageId:' . $id . ' payload:' . $message . ' execute fail。';
                if (is_object($error)) {
                    $errorMessage .= 'errorMsg:' . $error->getMessage() . ',errorFile:' . $error->getFile() . ',errorLine:' . $error->getLine() . ',trace:' . $error->getTraceAsString();
                } else {
                    $errorMessage .= 'errorMsg:' . $error;
                }
                self::$logDriver->write('queue/queue_consumer_error.log', $errorMessage);
            }
        }
        return $result;
    }
}
