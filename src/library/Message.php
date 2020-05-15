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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
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
     * @var string listenDriver
     */
    static $queueName;

    /**
     * @inheritdoc
     */
    public static function handleMessage($id, $message, $ttr, $attempt, $reconsumeTime = 60, $queueName = '')
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
        $error = '';
        $return = $result = true;
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
        if (false === $result) {
            self::pushNewMessage($event->id, $message, $event->ttr, $event->attempt, $reconsumeTime, $queueName);
            echo " execute " . 'fail， body：' . $message . "\n" . $error;
        }
        return $return;
    }

    /**
     * 用进程的方式推新的队列
     *
     * @author xyq
     * @param $id
     * @param $message
     * @param $ttr
     * @param $attempt
     * @param int $reconsumeTime
     * @param string $queueName
     * @return bool
     */
    public static function pushNewMessage($id, $message, $ttr, $attempt, $reconsumeTime = 60, $queueName = '')
    {
        $ttr = floatval(is_numeric($ttr) ? $ttr : 300);
        ++$attempt;
        // Executes child process
        $cmd = strtr('php run queue push "id" "ttr" "attempt" "pid" "reconsumeTime" "queueName"', [
            'php'           => PHP_BINARY,
            'run'           => $_SERVER['SCRIPT_FILENAME'],
            'queue'         => 'queue',
            'id'            => $id,
            'ttr'           => $ttr,
            'attempt'       => $attempt,
            'pid'           => getmypid(),
            'reconsumeTime' => $reconsumeTime,
            'queueName'     => $queueName,
        ]);
        $process = new Process($cmd, null, null, $message, $ttr);
        try {
            $result = $process->run();
            if (!in_array($result, [self::EXEC_DONE, self::EXEC_RETRY])) {
                throw new ProcessFailedException($process);
            }
            return $result === self::EXEC_DONE;
        } catch (ProcessRuntimeException $error) {
            if (is_object(self::$logDriver) && method_exists(self::$logDriver, 'write')) {
                self::$logDriver->write('queue/push_error_queue.log', ' messageId:' . $id . ' payload:' . $message);
            }
            return false;
        }
    }

    /**
     * 开启子进程
     *
     * @author xyq
     * @param $id
     * @param $message
     * @param $ttr
     * @param $attempt
     * @param int $reconsumeTime
     * @return bool
     */
    public static function exec($id, $message, $ttr, $attempt, $reconsumeTime = 60)
    {
        self::initParams();
        $job = self::$serialize->unSerialize($message);
        if (!($job instanceof JobInterface)) {
            throw new InvalidArgumentException("Job must be instance instead of JobInterface");
        }
        $ttr = floatval(is_numeric($ttr) ? $ttr : 300);
        ++$attempt;
        // Executes child process
        $cmd = strtr('php run queue exec "id" "ttr" "attempt" "pid" "reconsumeTime" "queueName"', [
            'php'           => PHP_BINARY,
            'run'           => $_SERVER['SCRIPT_FILENAME'],
            'queue'         => self::$queueName,
            'id'            => $id,
            'ttr'           => $ttr,
            'attempt'       => $attempt,
            'pid'           => getmypid(),
            'reconsumeTime' => $reconsumeTime,
        ]);
        $process = new Process($cmd, null, null, $message, $ttr);
        try {
            $result = $process->run();
            if (!in_array($result, [self::EXEC_DONE, self::EXEC_RETRY])) {
                throw new ProcessFailedException($process);
            }
            return $result === self::EXEC_DONE;
        } catch (ProcessRuntimeException $error) {
            return false;
        }
    }

    /**
     * 初始化当前执行的队列名称及其他参数
     *
     * @author xyq
     */
    private static function initParams()
    {
        self::$queueName = $_SERVER['argv'][1];
    }
}