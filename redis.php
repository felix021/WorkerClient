<?php

//examples on how to use WorkerClient

require_once __DIR__ . '/WorkerClient/Autoloader.php';
use WorkerClient\Worker;

$redis_password = '';

// Create a Worker Process Manager server
$worker = new Worker("redis://127.0.0.1:6379");

// number of worker processes (master process excluded)
$worker->count = 10;

$channel = pathinfo($argv[0])['filename'];

$onMessage = function ($connection, $data)
{
    if (!is_null($data)) {
        //do something
        file_put_contents('test.txt', $data . "\n", FILE_APPEND);
    }
    else {
        echo "brPop timeout, retry...\n";
    }
    if (Worker::getStatus() == Worker::STATUS_RUNNING) {
        global $channel;
        $connection->send("brPop {$channel} 3\n", true); #timeout = 3 seconds
    } else {
        $connection->close();
    }
};

$onLogin = function ($connection, $login)
{
    if ($login !== true)
    {
        echo "auth failed\n";
        $connection->close();
        return;
    }

    echo "auth ok\n";
    global $onMessage;
    $connection->onMessage = $onMessage;
    $onMessage($connection, null); //ugly but just working
};

// Emitted when new connection come
$worker->onConnect = function($connection)
{
    echo "connected to redis\n";
    global $redis_password;
    if ($redis_password) {
        $connection->send("AUTH {$redis_password}\n", true);
    }
    else {
        global $onLogin;
        $onLogin($connection, true); //no auth = success
    }
};

$worker->onMessage = $onLogin;

// Emitted when connection closed
$worker->onClose = function($connection)
{
    echo "Server Closed Connection, stop this worker.\n";
    //Master process will start a new worker process
    $connection->worker->stop(); 
};

// Run worker
Worker::runAll();
