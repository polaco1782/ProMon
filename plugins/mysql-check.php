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

class MySQL_Replication extends \API\PluginApi
{
    static $priority = 110;
    static $extension = ['mysqli'];

    private $master_pos = 0;
    private $slave_pos = [];

    // constructor registers plugin type and name
    public function __construct()
    {
        parent::__construct(CHECK_PLUGIN);
    }

    public function render()
    {
        
    }

    public function run(): void
    {
        $this->METRIC_ADD('mysql_connect_miliseconds', 0);
        $this->METRIC_ADD('mysql_query_miliseconds', 0);
        $this->METRIC_ADD('mysql_failed_queries', 0);
        $this->METRIC_ADD('mysql_failed_connects', 0);
        $this->measure_time(true);

        $test = bin2hex(random_bytes(20));

        foreach($this->config->datacenters as $dc)
        {
            foreach($dc->servers as $server)
            {
                // insert test value into master databases
                if($server->type == 'master')
                {
                    $master = new \mysqli($server->host, $server->user, $server->password, NULL, $server->port);

                    if($master->connect_error)
                    {
                        $this->CRITICAL("Couln't connect to {$dc->name} MySQL server {$server->host}, [" . $master->connect_error . "]");
                        $this->METRIC_INC('mysql_failed_connects');
                    }
                    else{
                        $this->METRIC_STORE('mysql_connect_miliseconds', $this->measure_time());

                        // create test DB if does not exists
                        if(!$master->real_query("create database if not exists replication_test"))
                            $this->CRITICAL("Failed to create database on {$server->host}, [" . $master->error . "]");

                        // create test table if does not exists
                        if(!$master->real_query("create table if not exists replication_test.test (id int primary key auto_increment, test varchar(255))"))
                            $this->CRITICAL("Failed to create table on {$server->host}, [" . $master->error . "]");

                        // test and run a table cleanup
                        if(!$master->real_query("select count(id) as cnt from replication_test.test"))
                            $this->CRITICAL("Failed to select from table on {$master->host}, [" . $master->error . "]");
                        else
                        {
                            if(!$result = $master->store_result())
                                $this->CRITICAL("Failed to fetch from table on {$server->host}, [" . $master->error . "]");
                            else
                            {
                                $obj = $result->fetch_assoc();
                                $result->free_result();

                                // check for number of records
                                if((int)$obj['cnt'] > 1000)
                                {
                                    // truncate table records
                                    if(!$master->real_query("truncate table replication_test.test"))
                                        $this->CRITICAL("Failed to truncate table on {$master->host}, [" . $master->error . "]");
                                    else
                                    $this->LOG("Table truncated, {$obj['cnt']} records purged");
                                }
                            }
                        }

                        // insert test data into table
                        if(!$master->real_query("insert into replication_test.test (test) values ('{$test}')"))
                            $this->CRITICAL("Failed to insert value on {$server->host}, [" . $master->error . "]");
                    }

                    $master->close();
                }
                else if($server->type == 'disabled')
                {
                    // disabled host
                    $this->LOG("{$server->host} is disabled, skipping");
                }
            }

            // wait for slaves to catch up
            sleep($this->config->sync_delay);

            // check slaves for test value
            foreach($dc->servers as $server)
            {
                if($server->type == 'slave')
                {
                    $slave = new \mysqli($server->host, $server->user, $server->password, NULL, $server->port);
                    if($slave->connect_error)
                    {
                        $this->CRITICAL("Couln't connect to {$dc->name} MySQL server {$server->host}, [" . $slave->connect_error . "]");
                        $this->METRIC_INC('mysql_failed_connects');
                    }
                    else{
                        $this->METRIC_STORE('mysql_connect_miliseconds', $this->measure_time());

                        if(!$slave->real_query("select * from replication_test.test where test = '{$test}'"))
                            $this->CRITICAL("Failed to select from table on {$server->host}, [" . $slave->error . "]");

                        if(!$result = $slave->store_result())
                            $this->CRITICAL("Failed to select from table on {$server->host}, [" . $slave->error . "]");
                        else
                        {
                            $obj = $result->fetch_assoc();
                            $result->free_result();
    
                            if($obj['test'] != $test)
                                $this->CRITICAL("SLAVE OUT OF SYNC: Value on {$server->host} is not the same as master!");
                            else
                                $this->LOG("SLAVE IS IN SYNC: Value on {$server->host} is the same as master!");
                        }

                        // query slave for current position
                        if(!$result = $slave->query("show slave status"))
                        {
                            $this->CRITICAL("Failed to execute query on {$server->host}, [" . $slave->error . "]");
                            $this->METRIC_INC('mysql_failed_queries');
                        }
                        else
                        {
                            $obj = $result->fetch_assoc();
                            $result->free_result();

                            // test if slave IO thread is running
                            if ($obj['Slave_IO_Running']!='Yes')
                            {
                                $this->CRITICAL("Slave IO is not running on host {$server->host}, ".$obj['Slave_IO_State']);
                                $slave->real_query("start slave");
                            }

                            // test if slave SQL thread is running
                            if ($obj['Slave_SQL_Running']!='Yes')
                            {
                                $this->CRITICAL("Slave SQL is not running on host {$server->host}, ".$obj['Slave_SQL_Running_State']);
                                $slave->real_query("start slave");
                            }

                            if ((int)$obj['Seconds_Behind_Master'] > $this->config->seconds_behind_master)
                                $this->WARN("Replication is running slow on {$server->host}, more than {$this->config->seconds_behind_master} seconds lag!");

                            $this->LOG($obj['Slave_IO_State']);
                            $this->LOG($obj['Slave_SQL_Running_State']);
                            $this->LOG('Last master position: '.$obj['Exec_Master_Log_Pos']);
                        }

                        // detect long running queries
                        if(!$result = $slave->query("SELECT * FROM INFORMATION_SCHEMA.PROCESSLIST where STATE='executing'"))
                            $this->CRITICAL("Failed to select from table on {$server->host}, [" . $slave->error . "]");
                        else
                        {
                            while ($row = $result->fetch_assoc())
                            {
                                // critical threshold
                                if((int)$row['TIME'] >= $this->config->threshold_critical)
                                {
                                    $this->CRITICAL("SLOW query detected in '".$row['DB']."' from '".$row['HOST']."', running for more than {$this->config->threshold_critical} seconds!");
                                    $this->CRITICAL($row['INFO']);
                                }
                                else if((int)$row['TIME'] >= $this->config->threshold_warn)
                                {
                                    $this->WARN("LONG query detected in '".$row['DB']."' from '".$row['HOST']."', running for more than {$this->config->threshold_warn} seconds!");
                                    $this->WARN($row['INFO']);
                                }
                            }
                        }

                        $result->free_result();
                    }

                    $slave->close();
                }
                else if($server->type == 'disabled')
                {
                    // disabled host
                    $this->LOG("{$server->host} is disabled, skipping");
                }
            }
        }
    }
}