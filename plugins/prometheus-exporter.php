<?php

/*
MIT License
Copyright (c) 2021 Cassiano Martin
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

namespace Plugin;

use \API\PluginApi;

// used by php server to fetch data
if (php_sapi_name() == 'cli-server') {
    $redis = new \Redis();
    $redis->connect('localhost');

    var_dump($redis->keys('*'));
}

class Prometheus extends \API\PluginApi
{
    static $priority = 10;
    static $extension = ['redis', 'pcntl'];

    static $redis;
    static $pid;

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(METRIC_PLUGIN);

        $this->register_call(
            'METRIC_STORE',
            function ($caller, $msg) {
                try {
                    self::$redis->set('prometheus_' . preg_replace('/\s+/', '_', $msg[0]), $msg[1]);
                } catch (\Exception $e) {
                    $this->ERROR($e->getMessage());
                }
            }
        );

        $this->register_call(
            'METRIC_ADD',
            function ($caller, $msg) {
                try {
                    self::$redis->set('prometheus_' . preg_replace('/\s+/', '_', $msg[0]), $msg[1]);
                } catch (\Exception $e) {
                    $this->ERROR($e->getMessage());
                }
            }
        );

        $this->register_call(
            'METRIC_INC',
            function ($caller, $msg) {
                try {
                    self::$redis->incr('prometheus_' . preg_replace('/\s+/', '_', $msg[0]));
                } catch (\Exception $e) {
                    $this->ERROR($e->getMessage());
                }
            }
        );

        self::$redis = new \Redis();
        try {
            self::$redis->connect('localhost');
        } catch (\Exception $e) {
            $this->CRITICAL("Could not connect to redis server: " . $e->getMessage());
        }

        if($this->config->web_enabled)
            $this->start_webserver();
    }

    public function __destruct()
    {
        if($this->config->web_enabled)
            posix_kill(self::$pid, SIGTERM);

        self::$redis->close();
    }

    public function run(): void
    {
    }

    public function start_webserver()
    {
        //pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);

        switch (self::$pid = pcntl_fork()) {
            case -1: // failed to create process
                throw new \Exception('ERROR: fork() failed!');
            case 0: // child
                pcntl_exec(PHP_BINARY, ['-S', '0.0.0.0:'.$this->config->web_port, __FILE__]);
                throw new \Exception('ERROR: exec() failed!');
        }

        //pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD], $xxx);
    }
}
