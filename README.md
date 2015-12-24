# WorkerClient

## What is it
WorkerClient derives from [Workerman](https://github.com/walkor/Workerman), but only serves as TCP client workers, which means this project only utilized the process manager from Workerman.

## Requires

PHP 5.3 or Higher  
A POSIX compatible operating system (Linux, OSX, BSD)  
POSIX and PCNTL extensions for PHP  
Libevent extension is prefered for better performance, which is not necessary

## Basic Usage

Try `redis.php` which implements redis auth/brPop commands in pure php.

## LICENSE

WorkerClient is released under the MIT license
