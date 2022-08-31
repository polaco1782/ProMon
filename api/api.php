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

define('API_PLUGIN', (1 << 0));
define('CHECK_PLUGIN', (1 << 1));
define('LOGGING_PLUGIN', (1 << 2));
define('WARN_PLUGIN', (1 << 3));
define('METRIC_PLUGIN', (1 << 4));

if(extension_loaded('swoole')) {
    require_once __DIR__ . '/api_swoole.php';
} elseif(extension_loaded('pcntl')) {
    require_once __DIR__ . '/api_pcntl.php';
} else {
    require_once __DIR__ . '/api_single.php';
}

class Autoloader
{
    use Runner;
    static $plugins = [];
    static $disabled = [];
    static $conf;

    static $processes, $signal_queue;

    // this function scans and load all files from plugins directory.
    // they should have specific entry point functions/variables.
    // see plugin-skeleton.sample for further information
    public static function load_plugins()
    {
        self::$conf = json_decode(file_get_contents('conf/promon.json'));

        if(!self::$conf) {
            trigger_error('ERROR: Could not load configuration file!', E_USER_ERROR);
        }

        if (!self::$conf->Autoloader) {
            trigger_error("Autoloader configuration section is broken or missing!", E_USER_ERROR);
        }

        // load each plugin file
        foreach (glob("plugins/{,*/}*.php", GLOB_BRACE) as $filename) {
            include_once $filename;
        }

        $classes = [];
        foreach (get_declared_classes() as $class) {
            $k = new \ReflectionClass($class);

            // skip internal PHP code
            if ($k->isInternal() == true)
                continue;

            $namespace = $k->getNamespaceName();
            $classname = $k->getShortName();

            // skip classes that are not plugins
            if(!is_subclass_of($class, 'API\PluginApi'))
            {
                __debug("Class {$classname} is not in Plugin namespace!");
                self::$disabled[] = $classname;
                continue;
            }

            // check if PHP has needed extension for plugin
            if(!empty($class::$extension)) {
                foreach($class::$extension as $ext) {
                    if(!extension_loaded($ext)) {
                        __debug(GREEN."WARNING: ".GRAY."Plugin {$classname} was disabled because it requires PHP extension {$ext}!");
                        self::$disabled[] = $classname;
                        continue 2;
                    }
                }
            }

            // check if plugin has configuration section
            if (isset(self::$conf->Plugins->{$classname})) {
                if (isset(self::$conf->Plugins->{$classname}->enabled)) {
                    if (!self::$conf->Plugins->{$classname}->enabled) {
                        __debug("Plugin {$classname} was disabled in configuration!");
                        self::$disabled[] = $classname;
                        continue;
                    }
                }
            } else {
                __debug(GREEN."WARNING: ".GRAY."Plugin {$classname} disabled, it has no configuration section!");
                self::$disabled[] = $classname;
                continue;
            }

            // new class load prio code
            __debug("CLASS PRIO: $class: {$class::$priority}");

            // class with duplicate prio value
            if(!empty($classes[$class::$priority]))
                trigger_error("ERROR: Duplicate priority for plugin {$classname}!", E_USER_ERROR);

            $classes[$class::$priority] = $class;
        }

        // check for class dependencies, if disabled, disable plugin
        foreach($classes as $key => $class) {
            $check = array_filter(self::$disabled, function($v) use ($class) { if(!empty($class::$depends)) return in_array($v, $class::$depends); });

            if(count($check) > 0) {
                __debug(GREEN."WARNING: ".GRAY."Plugin {$class} was disabled because it depends on disabled plugins: ".implode(', ', $check));
                unset($classes[$key]);
                continue;
            }
        }

        // sort by prio value
        if(!ksort($classes))
            __debug("Sort failed? Something is broken!");

        // now we do the real loading
        foreach ($classes as $class) {
            self::$plugins[$class] = new $class();
        }

        __debug(YELLOW."Loaded ".count(self::$plugins)." plugins!".GRAY);
    }

    public static function unload_plugins()
    {
        foreach (self::$plugins as &$p) {
            unset($p);
        }
    }

    // checks if at least one desired class type is loaded
    public static function is_enabled($type)
    {
        foreach (self::$plugins as &$p) {
            if ($p->type == $type) {
                return true;
            }
        }

        return false;
    }

    public static function dump_plugins()
    {
        var_dump(self::$plugins);
    }

    public static function single_run($plugin)
    {
        if (isset(self::$plugins['Plugin\\'.$plugin])) {
            self::$plugins['Plugin\\'.$plugin]->run();
        } else {
            trigger_error("Plugin {$plugin} not found!", E_USER_NOTICE);
        }
    }
}

class PluginApi
{
    public $plugin;
    public $type;
    private $crontab;
    private $exectime;
    protected $configs;

    static $calls = [];

    // calls a dynamic registered function
    public function __call($method, $args)
    {
        $found = false;
        foreach (self::$calls as $call) {
            // supress: call to an unknown method, falls into next exception.
            if (@isset($call[$method])) {
                $call[$method]($this->plugin, $args);
                $found = true;
            }
        }

        // trap, but not crash on failed calls
        if (!$found) {
            __debug("Method not implemented: " . $method . "(), make sure plugin is loaded before call().\n");
        }
    }

    // same as above, but for singleton static calls
    public static function call($method, $args)
    {
        $found = false;
        $trace = debug_backtrace()[0];
        foreach (self::$calls as $call) {
            // supress: call to an unknown method, falls into next exception.
            // add a singleton 'self' to the string so we can detect where it came from
            if (@isset($call[$method])) {
                $call[$method]('self\\'.basename($trace['file']), [$args]);
                $found = true;
            }
        }

        // trap, but not crash on failed calls
        if (!$found) {
            __debug("Method not implemented: " . $method . "(), make sure plugin is loaded before call().\n");
        }
    }

    public function __construct($type)
    {
        // register plugin name
        $this->plugin = get_called_class();
        $this->type = $type;

        $class = explode('\\', $this->plugin)[1];
        $conf = json_decode(file_get_contents('conf/promon.json'))->Plugins;

        if (!$conf) {
            trigger_error("Plugin configuration section is broken or missing!");
        }

        // supress: section may be missing. (eg: no config needed)
        $this->config = @$conf->{$class} ?: null;
    }

    // register a new dynamic call
    public function register_call($call, $func)
    {
        self::$calls[][$call] = $func;
    }

    public function parse_crontab()
    {
        // ignore log plugins crontab for now
        if ($this->type == LOGGING_PLUGIN || $this->type == WARN_PLUGIN) {
            return;
        }

        // ignore empty crontabs
        if (!isset($this->config->crontab) || $this->config->crontab == null) {
            __debug('No crontab settings for ' . $this->plugin . '. Check configuration file or plugin will not run!');
            return;
        }

        $time = explode(' ', date('i G j n w'));
        $crontab = explode(' ', trim($this->config->crontab));

        if (count($crontab) != 5) {
            trigger_error("Broken crontab section for {$this->plugin}!");
        }

        foreach ($crontab as $k => &$v) {
            $time[$k] = preg_replace('/^0+(?=\d)/', '', $time[$k]);
            $v = explode(',', $v);

            foreach ($v as &$v1) {
                $v1 = preg_replace(
                    ['/^\*$/', '/^\d+$/', '/^(\d+)\-(\d+)$/', '/^\*\/(\d+)$/'],
                    ['true', $time[$k] . '===\0', '(\1<=' . $time[$k] . ' and ' . $time[$k] . '<=\2)', $time[$k] . '%\1===0'],
                    $v1
                );
            }

            $v = '(' . implode(' or ', $v) . ')';
        }

        $code = eval('return (' . implode(' and ', $crontab) . ');');

        __debug('evaluated ' . $this->plugin . ' crontab code: ' . ($code ? 'true' : 'false'));

        return $code;
    }

    public function measure_time($init = false)
    {
        if ($init) {
            $this->exectime = microtime(true);
        } else {
            return round((microtime(true) - $this->exectime), 4);
        }
    }
}
