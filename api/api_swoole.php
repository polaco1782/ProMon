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

namespace API;

use \API\PluginApi;

trait Runner
{
    public static function run_plugins()
    {
        $processes = [];

        foreach (self::$plugins as $p)
        {
            // run each instance into a forked process
            if (self::$conf->Autoloader->fork_plugins && $p->parse_crontab()) {
                $p = new \Swoole\Process(function() use ($p) {
                    $p->run();
                });
                $processes[] = $p;
                $p->start();
            } else {
                if ($p->parse_crontab()) {
                    $p->run();
                }
            }
        }

        // wait for all processes to finish
        foreach ($processes as $p) {
            $p->wait();
        }

        __debug("SWOOLE: Dispatched all plugins!");
    }
}