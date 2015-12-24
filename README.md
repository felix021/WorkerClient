# WorkerClient

## What is it
WorkerClient derives from [Workerman](https://github.com/walkor/Workerman), but only serves as TCP client workers, which means this project only utilized the process manager from Workerman.

## Requires

PHP 5.3 or Higher  
A POSIX compatible operating system (Linux, OSX, BSD)  
POSIX and PCNTL extensions for PHP  
Libevent extension is prefered for better performance, which is not necessary

## Basic Usage

test.php
```php
//examples on how to use WorkerClient

require_once __DIR__ . '/WorkerClient/Autoloader.php';
use WorkerClient\Worker;

// Create a Worker Process Manager server
$worker = new Worker("redis://127.0.0.1:6379");

// number of worker processes (master process excluded)
$worker->count = 4;

$channel = pathinfo($argv[0])['filename'];

function brPop($connection)
{
    global $channel;
    $connection->send("brPop {$channel} 3\n", true); #timeout = 3 seconds
}

// Emitted when new connection come
$worker->onConnect = function($connection)
{
    echo "connected to redis\n";
    brPop($connection);
};

// Emitted when data received
$worker->onMessage = function($connection, $data)
{
    echo "brPop: $data\n";
    if (!is_null($data)) {
        //do something
    }
    if (Worker::getStatus() == Worker::STATUS_RUNNING) {
        brPop($connection);
    } else {
        $connection->close();
    }
};

// Emitted when connection closed
$worker->onClose = function($connection)
{
    echo "Server Closed Connection, stop this worker.\n";
    //Master process will start a new worker process
    $connection->worker->stop(); 
};

// Run worker
Worker::runAll();
```

## LICENSE

WorkerClient is released under the MIT license
