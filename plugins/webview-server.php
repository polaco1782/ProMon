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

    exit();
}

class WebView extends \API\PluginApi
{
    static $priority = 11;
    static $extension = ['pcntl', 'redis'];
    static $pid;

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(LOGGING_PLUGIN);

        if($this->config->web_enabled)
            $this->start_webserver();
    }

    public function __destruct()
    {
        if($this->config->web_enabled)
            posix_kill(self::$pid, SIGTERM);
    }

    public function render()
    {

    }

    public function start_webserver()
    {
        //pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);

        switch (self::$pid = pcntl_fork()) {
            case -1: // failed to create process
                trigger_error('ERROR: fork() failed!', E_USER_ERROR);
            case 0: // child
                pcntl_exec(PHP_BINARY, ['-S', '0.0.0.0:8000', __FILE__]);
                trigger_error('ERROR: exec() failed!', E_USER_ERROR);
        }

        //pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD], $xxx);
    }
}
