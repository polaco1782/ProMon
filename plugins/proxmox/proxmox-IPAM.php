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

class ProxMox_IPAM extends \API\PluginApi
{
    static $priority = 180;
    static $extension = ['curl'];
    static $depends = ['ProxMox'];

    protected static $curl;
    protected static $token;
    protected static $subnets;

    public $error_codes = [
            // OK
            200 => "OK",
            201 => "Created",
            202 => "Accepted",
            204 => "No Content",
            // Client errors
            400 => "Bad Request",
            401 => "Unauthorized",
            403 => "Forbidden",
            404 => "Not Found",
            405 => "Method Not Allowed",
            415 => "Unsupported Media Type",
            // Server errors
            500 => "Internal Server Error",
            501 => "Not Implemented",
            503 => "Service Unavailable",
            505 => "HTTP Version Not Supported",
            511 => "Network Authentication Required"
    ];

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);

        $this->getToken();
    }

    // request token from php IPAM api
    public function getToken()
    {
        self::$curl = curl_init();

        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/user/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
              'Authorization: Basic ' . base64_encode($this->config->api_key)
            ],
        ]);
        
        $response = curl_exec(self::$curl);

        // decode response
        $response = json_decode($response);

        self::$token = $response->data->token;
    }

    // get all IPs from php IPAM api
    public function getAllIps()
    {
        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/addresses/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
              'token: ' . self::$token
            ],
        ]);
        
        $response = json_decode(curl_exec(self::$curl));

        return $response->data;
    }

    // find to which subnet an IP belongs
    public function findSubnet($addr)
    {
        if (!self::$subnets) {
            curl_setopt_array(self::$curl, [
                CURLOPT_URL => $this->config->api_url . '/subnets/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'token: ' . self::$token
                ]
            ]);

            $response = json_decode(curl_exec(self::$curl), true);
            self::$subnets = $response['data'];
        }
        
        $ids = array_column(self::$subnets, 'id');
        $nets = array_column(self::$subnets, 'subnet');
        $masters = array_column(self::$subnets, 'masterSubnetId');
        $mask = array_column(self::$subnets, 'mask');

        $id = array_filter(array_map(function ($net, $mask, $id) use ($addr, $masters) {
            $mask = intval($mask);
            $net = ip2long($net);
            $ip = ip2long($addr);

            if (!in_array($id, $masters)) {
                // check if ipv4 is in subnet range
                if (($ip & ~((1 << (32 - $mask)) - 1)) == $net) {
                    return $id;
                }
            }
        }, $nets, $mask, $ids));

        return array_pop($id);
    }

    // post IP to php IPAM api
    public function postIp($ip, $hostname, $description = '')
    {
        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/addresses/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'token: ' . self::$token
            ],
            CURLOPT_POSTFIELDS => '{
                "ip": "' . $ip . '",
                "hostname": "' . $hostname . '",
                "description": "' . $description . '",
                "note": "' . $this->config->note . '",
                "lastSeen": "' . date('Y-m-d H:i:s') . '",
                "subnetId": ' . $this->findSubnet($ip) . '
            }',
        ]);

        $response = json_decode(curl_exec(self::$curl));

        // on conflict address, update it
        if ($response->code == 409) {
            $this->updateIp($ip, $hostname, $description);
        }
    }

    // update IP to php IPAM api
    public function updateIp($ip, $hostname, $description = '')
    {
        $id = (int)array_column($this->findID($ip), 'id')[0];

        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/addresses/' . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'token: ' . self::$token
            ],
            CURLOPT_POSTFIELDS => '{
                "id": ' . $id . ',
                "hostname": "' . $hostname . '",
                "description": "' . $description . '",
                "lastSeen": "' . date('Y-m-d H:i:s') . '",
                "note": "' . $this->config->note . '"
            }',
        ]);

        $response = json_decode(curl_exec(self::$curl));
    }

    public function deleteIp($ip)
    {
        $id = (int)array_column($this->findID($ip), 'id')[0];

        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/addresses/' . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'token: ' . self::$token
            ]
        ]);

        $response = json_decode(curl_exec(self::$curl));
    }

    // find ID into IPAM api
    public function findID($ip)
    {
        curl_setopt_array(self::$curl, [
            CURLOPT_URL => $this->config->api_url . '/addresses/search/' . $ip,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'token: ' . self::$token
            ]
        ]);

        $response = json_decode(curl_exec(self::$curl));

        return $response->data;
    }

    public function update_containers($l)
    {
        if ($l->status == "unknown") {
            $this->WARN("Container " . $l->node . "/" . $l->id . " is in unknown state");
            return;
        }

        $z = Proxmox::request("/nodes/{$l->node}/lxc/{$l->vmid}/config")->data;

        $nets = preg_grep('/^net[\d]*/', array_keys((array)$z));

        // parse each network device result
        if (!empty($nets)) {
            foreach ($nets as $net) {
                preg_match('/(?<=ip=)[^\/]+/', $z->{$net}, $out);

                if (count($out)) {
                    $this->LOG("Update container {$l->name}, address {$out[0]}");
                    $this->postIp($out[0], $l->name, '#ON PROXMOX NODE: '.$l->node);
                }
            }
        }
    }

    public function update_vms($l)
    {
        if ($l->status == "unknown") {
            $this->WARN("VM " . $l->node . "/" . $l->id . " is in unknown state");
            return;
        }

        $z = Proxmox::request("/nodes/{$l->node}/qemu/{$l->vmid}/config")->data;
        $nets = preg_grep('/^net[\d]*/', array_keys((array)$z));

        // parse each network device result
        if (!empty($nets)) {
            foreach ($nets as $net) {
                // find mac address on the string
                preg_match("/([a-f0-9]{2}[:|\-]?){6}/i", $z->{$net}, $m);
                //print_r($m[0]);

                print($m[0] . " " . $l->name . "\n");
            }
        }
    }

    public function update_nodes($l)
    {
        $y = Proxmox::request("/nodes/{$l->node}/hosts")->data->data;  // wtf?

        // reading IP address from hosts file.
        // this is a crude hack to get the IP address, because proxmox API does not
        // returns the correct IP address from the node status :(
        foreach (explode("\n", $y) as $yy) {
            $z = explode(" ", $yy);
            if (count($z) >= 3) {
                if ($z[2] == $l->node) {
                    $this->postIp($z[0], $l->node, '@PROXMOX HOST: ' . $l->node);
                    $this->LOG("Update node {$l->node}, address {$z[0]}");
                    break;
                }
            }
        }
    }

    public function update_static_addresses()
    {
        foreach($this->config->static_hosts as $host)
        {
            foreach($host->ip as $addr)
            {
                $this->postIp($addr, $host->hostname, '+STATIC HOST: ' . $host->hostname . ' '.$host->note);
                $this->LOG("Update static address {$addr}, hostname {$host->hostname}");
            }
        }
    }

    public function removeUnusedIps($l)
    {
        $ips = $this->getAllIps();

        // skip records not added by this script
        $list = array_filter($ips, function ($ip) {
            return $ip->note == $this->config->note;
        });

        // fetch columns from each source
        $i = array_column($list, 'hostname');   // IPAM records
        $p = array_column($l, 'name');          // Container records
        $n = array_column($l, 'node');          // Proxmox Node records

        // compute difference between IPAM and Proxmox records
        $diff = array_diff($i, $p);
        $diff2 = array_diff($diff, $n);

        // delete IPAM records not present on Proxmox
        print_r($diff2);
    }

    public function run(): void
    {
        // read cluster information
        $l = Proxmox::request("/cluster/resources")->data;

        // NOTE: need to load all proxmox resources
        $this->removeUnusedIps($l);

        //$this->update_static_addresses();
        exit();

        // parse each host/container/vm status
        foreach ($l as $ll) {
            switch ($ll->type) {
                case 'node':
                    $this->update_nodes($ll);
                    break;
                case 'qemu':
                    $this->update_vms($ll);
                    break;
                case 'lxc':
                    $this->update_containers($ll);
                    break;
            }
        }
    }
}
