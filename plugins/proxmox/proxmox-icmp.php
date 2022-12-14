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

function check_private($addr)
{
    $ip = ip2long($addr);

    $localaddr = [
      [ip2long('127.0.0.0'),   24],
      [ip2long('10.0.0.0'),    24],
      [ip2long('172.16.0.0'),  20],
      [ip2long('192.168.0.0'), 16],
      [ip2long('169.254.0.0'), 16],
    ];

    foreach ($localaddr as $ll) {
       // check if between netmask
        if (($ip & ~((1 << $ll[1]) - 1)) === $ll[0]) {
            return true;
        }
    }

    return false;
}

class ProxMox_ICMP extends \API\PluginApi
{
    static $priority = 170;
    static $extension = ['curl'];
    static $depends = ['ProxMox'];

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);
    }
    
    public function run(): void
    {
        $l = Proxmox::request('/nodes');

        $cmd = "fping -A -a -q -r 0 -t " . $this->config->timeout;
        $hosts = [];
    
        foreach ($l->data as $ll) {
            $x = Proxmox::request("/nodes/{$ll->node}/lxc");
    
            foreach ($x->data as $xx) {
                $z = Proxmox::request("/nodes/{$ll->node}/lxc/{$xx->vmid}/config")->data;
                $st = Proxmox::request("/nodes/{$ll->node}/lxc/{$xx->vmid}/status/current")->data;

                $nets = preg_grep('/^net[\d]*/', array_keys((array)$z));

                if ($st->status == 'stopped') {
                    $this->LOG("Container {$st->name} is stopped, skipping...");
                    continue;
                }

                // parse each network device result
                if (!empty($nets)) {
                    foreach ($nets as $net) {
                        preg_match('/(?<=ip=)[^\/]+/', $z->{$net}, $out);
    
                        if (count($out)) {
                            $hosts[$z->hostname] = $out[0];
                        }
                    }
                }
            }
        }

        // execute and get result from ping test
        $out = shell_exec("{$cmd} " . implode(' ', $hosts));
        $out = explode("\n", $out);

        foreach (array_diff($hosts, $out) as $host => $diff) {
            $this->WARN("No ICMP response from {$host}/{$diff} address");
        }
    }
}
