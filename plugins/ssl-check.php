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
  
class SSL extends \API\PluginApi
{
    static $priority = 245;
    static $extension = ['openssl'];

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);
    }


    /*
array(16) {
  ["name"]=>
  string(19) "/CN=mail.vaitel.com"
  ["subject"]=>
  array(1) {
    ["CN"]=>
    string(15) "mail.vaitel.com"
  }
  ["hash"]=>
  string(8) "aebb8b6f"
  ["issuer"]=>
  array(3) {
    ["C"]=>
    string(2) "US"
    ["O"]=>
    string(13) "Let's Encrypt"
    ["CN"]=>
    string(2) "R3"
  }
  ["version"]=>
  int(2)
  ["serialNumber"]=>
  string(38) "0x04F8B0900FFA6FC1DD5615D9579C185ACEBA"
  ["serialNumberHex"]=>
  string(36) "04F8B0900FFA6FC1DD5615D9579C185ACEBA"
  ["validFrom"]=>
  string(13) "220715042956Z"
  ["validTo"]=>
  string(13) "221013042955Z"
  ["validFrom_time_t"]=>
  int(1657859396)
  ["validTo_time_t"]=>
  int(1665635395)
  ["signatureTypeSN"]=>
  string(10) "RSA-SHA256"
  ["signatureTypeLN"]=>
  string(23) "sha256WithRSAEncryption"
  ["signatureTypeNID"]=>
  int(668)
  ["extensions"]=>
  array(9) {
    ["keyUsage"]=>
    string(35) "Digital Signature, Key Encipherment"
    ["extendedKeyUsage"]=>
    string(60) "TLS Web Server Authentication, TLS Web Client Authentication"
    ["basicConstraints"]=>
    string(8) "CA:FALSE"
    ["subjectKeyIdentifier"]=>
    string(59) "7F:74:5C:84:F1:54:AF:01:0D:CA:FC:D6:05:27:39:A9:E3:80:87:06"
    ["authorityKeyIdentifier"]=>
    string(59) "14:2E:B3:17:B7:58:56:CB:AE:50:09:40:E6:1F:AF:9D:8B:14:C2:C6"
    ["authorityInfoAccess"]=>
    string(72) "OCSP - URI:http://r3.o.lencr.org
CA Issuers - URI:http://r3.i.lencr.org/"
    ["subjectAltName"]=>
    string(19) "DNS:mail.vaitel.com"
    ["certificatePolicies"]=>
    string(88) "Policy: 2.23.140.1.2.1
Policy: 1.3.6.1.4.1.44947.1.1.1
  CPS: http://cps.letsencrypt.org"
    ["ct_precert_scts"]=>
    string(1158) "Signed Certificate Timestamp:
    Version   : v1 (0x0)
    Log ID    : 41:C8:CA:B1:DF:22:46:4A:10:C6:A1:3A:09:42:87:5E:
                4E:31:8B:1B:03:EB:EB:4B:C7:68:F0:90:62:96:06:F6
    Timestamp : Jul 15 05:29:56.926 2022 GMT
    Extensions: none
    Signature : ecdsa-with-SHA256
                30:44:02:20:0A:A1:D2:E1:EC:64:3A:CD:1B:C5:D6:D1:
                46:BE:69:CF:5A:BF:86:E8:44:CA:39:CB:E4:54:A2:E9:
                5D:BC:3C:17:02:20:70:4F:8A:AD:F1:9E:9F:6D:CF:3F:
                10:CD:4A:26:87:0A:A4:A0:EF:1A:3B:90:63:B1:29:20:
                44:78:15:EE:81:D0
Signed Certificate Timestamp:
    Version   : v1 (0x0)
    Log ID    : 46:A5:55:EB:75:FA:91:20:30:B5:A2:89:69:F4:F3:7D:
                11:2C:41:74:BE:FD:49:B8:85:AB:F2:FC:70:FE:6D:47
    Timestamp : Jul 15 05:29:56.954 2022 GMT
    Extensions: none
    Signature : ecdsa-with-SHA256
                30:45:02:21:00:CE:05:A3:11:69:EF:F1:26:40:17:01:
                E0:1A:7E:08:69:0F:32:02:0D:7A:8E:8D:C6:D1:AB:71:
                3B:7E:5D:93:CD:02:20:64:8E:80:C7:53:F3:E0:83:CD:
                C8:F8:DA:C9:21:8F:3C:13:66:F7:77:C6:AE:64:B0:59:
                83:B9:A5:7F:5E:F9:F4"
  }
}
    */

    public function run(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
            ],
        ]);

        foreach($this->config->hosts as $host)
        {
            $socket = @stream_socket_client(
                "ssl://{$host->host}:{$host->port}",
                $code,
                $message,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($socket !== false) {
                $cert = stream_context_get_params($socket);
                $parsed = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
            }
            else {
                $this->LOG("FAILED to connect: {$host->host}:{$host->port} - {$code} - {$message}");
                continue;
            }

            // get current unxi timestamp
            $now = time();
            $diff = $parsed['validTo_time_t'] - $now;

            $days = floor($diff / (60 * 60 * 24));
            $hours = floor(($diff - ($days * 60 * 60 * 24)) / (60 * 60));
            $minutes = floor(($diff - ($days * 60 * 60 * 24) - ($hours * 60 * 60)) / 60);
            $seconds = floor(($diff - ($days * 60 * 60 * 24) - ($hours * 60 * 60) - ($minutes * 60)));
            $this->LOG("{$host->host} certificate expires in {$days} days, {$hours} hours, {$minutes} minutes, {$seconds} seconds, issued by ".implode(', ', $parsed['issuer']));

            if ($diff <= 0) {
                $this->CRITICAL("{$host->host} SSL certificate is expired!");
            }

            // close socket
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

    }
}
