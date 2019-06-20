<?php

namespace Swover\Server;

use Swover\Utils\Event;
use Swover\Worker;

/**
 * Process Server
 */
class Process extends Base
{
    /**
     * workers array, key is worker's process_id, value is array
     * [
     *     'id' => $worker_id,
     *     'process' => \swoole_process
     * ]
     * @var array
     */
    private $workers = [];

    protected function boot()
    {
        if (!extension_loaded('pcntl')) {
            throw new \Exception('Process required pcntl-extension!');
        }

        $this->start();
    }

    private function start()
    {
        if ($this->daemonize === true) {
            \swoole_process::daemon(true, false);
        }

        Event::getInstance()->trigger('master_start', posix_getpid());
        Worker::setMasterPid(posix_getpid());
        $this->_setProcessName('master');

        for ($i = 0; $i < $this->worker_num; $i++) {
            $this->createProcess($i);
        }

        $this->asyncProcessWait();
    }

    /**
     * create process
     */
    private function createProcess($index)
    {
        $process = new \swoole_process(function (\swoole_process $worker) use ($index) {

            $this->_setProcessName('worker_' . $index);
            Event::getInstance()->trigger('worker_start', $index);

            Worker::setStatus(true);

            pcntl_signal(SIGUSR1, function ($signo) {
                Worker::setStatus(false);
            });

            $this->execute();

            Event::getInstance()->trigger('worker_stop', $index);

            $worker->exit();
        }, $this->daemonize);

        $pid = $process->start();

        \swoole_event_add($process->pipe, function ($pipe) use ($process) {
            if ($message = $process->read()) {
                if ($log_file = $this->getConfig('log_file', '')) {
                    error_log(date('Y-m-d H:i:s') . ' ' . ltrim($message) . PHP_EOL, 3, $log_file);
                } else {
                    echo trim($message) . PHP_EOL;
                }
            }
        });

        $this->workers[$pid] = [
            'id' => $index,
            'process' => $process
        ];
        return $pid;
    }

    protected function execute($data = null)
    {
        $request_count = 0;
        $signal = 0;
        while (true) {
            $signal = $this->getProcessSignal($request_count);
            if ($signal > 0) {
                break;
            }

            try {
                Event::getInstance()->trigger('request', []);
                $response = $this->entrance();
                Event::getInstance()->trigger('response', $response);

                if ($response->code >= 400 || $response->code < 0) {
                    break;
                }

            } catch (\Exception $e) {
                echo "[Error] worker pid: " . Worker::getProcessId() . ", e: " . $e->getMessage() . PHP_EOL;
                break;
            }
        }
        return $signal;
    }

    /**
     * get child process sign
     * @return int
     */
    private function getProcessSignal(&$request_count)
    {
        if ($this->max_request > 0) {
            if ($request_count > $this->max_request) {
                return 1;
            }
            $request_count++;
        }

        if (!Worker::checkProcess(Worker::getMasterPid())) {
            return 2;
        }

        if (Worker::getStatus() == false) {
            return 3;
        }

        return 0;
    }

    /**
     * restart child process
     *
     * @param array $info array process info
     * [
     *     'pid' => 1234,
     *     'code' => 0,
     *     'signal' => 15
     * ]
     * @throws \Exception
     */
    private function restart($info)
    {
        if (!isset($this->workers[$info['pid']])) {
            throw new \Exception('restart process Error: no pid');
        }

        $worker = $this->workers[$info['pid']];

        \swoole_event_del($worker['process']->pipe);
        $worker['process']->close();

        unset($this->workers[$info['pid']]);

        $this->createProcess(intval($worker['id']));
    }

    /**
     * async listen SIGCHLD
     */
    private function asyncProcessWait()
    {
        \swoole_process::signal(SIGCHLD, function ($sig) {
            while ($ret = \swoole_process::wait(false)) {
                $this->restart($ret);
            }
        });
    }
}