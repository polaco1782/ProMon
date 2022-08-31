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

class Crash_Report extends \API\PluginApi
{
    static $priority = 0;

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(LOGGING_PLUGIN);

        set_error_handler([$this, 'crash_error_handler']);
        register_shutdown_function([$this, 'crash_shutdown_handler']);
    }

    // catch code errors and exceptions
    public function crash_shutdown_handler()
    {
        $error = error_get_last();

        if($error !== null) {
            $this->CRITICAL('PHP error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
            exit(1);
        }
    }

    public function crash_error_handler($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting, so let it fall
            // through to the standard PHP error handler
            return false;
        }
    
        switch ($errno) {
        case E_USER_ERROR:
            $this->CRITICAL('PHP Error: '.$errstr);
            exit(1);
    
        case E_USER_WARNING:
            $this->WARNING('WARNING: '.$errstr);
            break;
    
        case E_USER_NOTICE:
            $this->LOG('NOTICE: '.$errstr);
            break;
    
        default:
            __debug("Unknown error type: [$errno] $errstr");
            break;
        }
    
        /* Don't execute PHP internal error handler */
        return true;
    }
}
