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

class ProxMox_Network extends \API\PluginApi
{
    static $priority = 200;
    static $extension = ['curl'];
    static $depends = ['ProxMox'];

    private $logfmt = "[%s] Container %s has an IP conflict with %s, address: %s";

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);
    }
    
    public function run(): void
    {
        $hosts = [];

        // read cluster information
        $l = Proxmox::request("/cluster/resources")->data;

        foreach ($l as $ll) {
            // check only containers
            if ($ll->type != "lxc") {
                continue;
            }

            // check only running containers
            if ($ll->status == "unknown") {
                $this->WARN("Container " . $ll->node . "/" . $ll->id . " is in unknown state");
                continue;
            }

            // fetch config data from container
            $z = Proxmox::request("/nodes/{$ll->node}/lxc/{$ll->vmid}/config")->data;

            $nets = preg_grep('/^net[\d]*/', array_keys((array)$z));

            // parse each network device result
            if (!empty($nets)) {
                foreach ($nets as $net) {
                    preg_match('/(?<=ip=)[^\/]+/', $z->{$net}, $out);

                    if (count($out)) {
                        if (isset($hosts[$out[0]])) {
                            $this->CRITICAL(sprintf($this->logfmt, $ll->status, $ll->name, $hosts[$out[0]], $out[0]));
                        } else {
                            $this->LOG("Checking container {$ll->name}, address {$out[0]}");
                        }

                        // store hostname
                        $hosts[$out[0]] = $ll->name;
                    }
                }
            }
        }
    }
}
