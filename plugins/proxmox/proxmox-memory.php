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

class ProxMox_Memory extends \API\PluginApi
{
    static $priority = 190;
    static $extension = ['curl'];
    static $depends = ['ProxMox'];

    private $logfmt = "MEM Threshold reached for (%s): %s: %s of %s : %0.2f%%";

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);
    }
    
    public function run(): void
    {
        // read cluster information
        $l = Proxmox::request("/cluster/resources")->data;

        foreach ($l as $ll) {
            if ($ll->status == 'running') {

                $usage = ($ll->mem * 100) / $ll->maxmem;

                if($ll->type == 'qemu') {
                    continue;
                }

                switch (true) {
                    case ($usage >= $this->config->threshold_critical):
                        $this->CRITICAL(sprintf($this->logfmt, $ll->type, $ll->name, formatBytes($ll->mem), formatBytes($ll->maxmem), $usage));
                        break;
                    case ($usage >= $this->config->threshold_warn):
                        $this->WARN(sprintf($this->logfmt, $ll->type, $ll->name, formatBytes($ll->mem), formatBytes($ll->maxmem), $usage));
                        break;
                    default:
                        $this->LOG(sprintf("Checking %s memory: %s (%0.2f%% used)", $ll->name, formatBytes($ll->maxmem), $usage));
                        break;
                }
            }
        }
    }
}
