<?php
/**
 * This file is part of workerclient.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 *
 * @edited by: felix021<felix021@gmail.com>
 */
namespace WorkerClient;

require_once __DIR__.'/Lib/Constants.php';

use \WorkerClient\Events\Libevent;
use \WorkerClient\Events\Select;
use \WorkerClient\Events\EventInterface;
use \WorkerClient\Connection\ConnectionInterface;
use \WorkerClient\Connection\TcpConnection;
use \WorkerClient\Connection\AsyncTcpConnection;
use \WorkerClient\Lib\Timer;
use \Exception;

/**
 * Worker 类
 * 是一个容器，用于监听端口，维持客户端连接
 */
class Worker
{
    /**
     * 版本号
     * @var string
     */
    const VERSION = '3.2.5';
    
    /**
     * 状态 启动中
     * @var int
     */
    const STATUS_STARTING = 1;
    
    /**
     * 状态 运行中
     * @var int
     */
    const STATUS_RUNNING = 2;
    
    /**
     * 状态 停止
     * @var int
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * 状态 平滑重启中
     * @var int
     */
    const STATUS_RELOADING = 8;
    
    /**
     * 给子进程发送重启命令 KILL_WORKER_TIMER_TIME 秒后
     * 如果对应进程仍然未重启则强行杀死
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 5;
    
    /* 连接仍然活跃的时候，延迟退出 */
    const EXIT_RETRY_DELAY  = 1;

    /* 在stop()方法调用中指定该参数，则不调用exit */
    const DELAY_EXIT        = false;

    /**
     * 默认的backlog，即内核中用于存放未被进程认领（accept）的连接队列长度
     * @var int
     */
    const DEFAUL_BACKLOG = 1024;
    
    /**
     * worker id
     * @var int
     */
    public $id = 0;
    
    /**
     * worker的名称，用于在运行status命令时标记进程
     * @var string
     */
    public $name = '';
    
    /**
     * 设置当前worker实例的进程数
     * @var int
     */
    public $count = 1;
    
    /**
     * 设置当前worker进程的运行用户，启动时需要root超级权限
     * @var string
     */
    public $user = '';
    
    /**
     * 当前worker进程是否可以平滑重启 
     * @var bool
     */
    public $reloadable = true;

    /**
     * reuse port
     * @var bool
     */
    public $reusePort = false;
    
    /**
     * 当worker进程启动时，如果设置了$onWorkerStart回调函数，则运行
     * 此钩子函数一般用于进程启动后初始化工作
     * @var callback
     */
    public $onWorkerStart = null;
    
    /**
     * 当有客户端连接时，如果设置了$onConnect回调函数，则运行
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * 当客户端连接上发来数据时，如果设置了$onMessage回调，则运行
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * 当客户端的连接关闭时，如果设置了$onClose回调，则运行
     * @var callback
     */
    public $onClose = null;
    
    /**
     * 当客户端的连接发生错误时，如果设置了$onError回调，则运行
     * 错误一般为客户端断开连接导致数据发送失败、服务端的发送缓冲区满导致发送失败等
     * 具体错误码及错误详情会以参数的形式传递给回调，参见手册
     * @var callback
     */
    public $onError = null;
    
    /**
     * 当连接的发送缓冲区满时，如果设置了$onBufferFull回调，则执行
     * @var callback
     */
    public $onBufferFull = null;
    
    /**
     * 当链接的发送缓冲区被清空时，如果设置了$onBufferDrain回调，则执行
     * @var callback
     */
    public $onBufferDrain = null;
    
    /**
     * 当前进程退出时（由于平滑重启或者服务停止导致），如果设置了此回调，则运行
     * @var callback
     */
    public $onWorkerStop = null;
    
    /**
     * 当收到reload命令时的回调函数
     * @var callback
     */
    public $onWorkerReload = null;
    
    /**
     * 客户端连接
     * @var array
     */
    public $connection = null;
    
    /**
     * 应用层协议，由初始化worker时指定
     * 例如 new worker('http://0.0.0.0:8080');指定使用http协议
     * @var string
     */
    protected $_protocol = '';
    
    /**
     * 当前worker实例初始化目录位置，用于设置应用自动加载的根目录
     * @var string
     */
    protected $_appInitPath = '';
    
    /**
     * 是否以守护进程的方式运行。运行start时加上-d参数会自动以守护进程方式运行
     * 例如 php start.php start -d
     * @var bool
     */
    public static $daemonize = false;

    /**
     * 是否强制reload/stop/restart
     * 例如 php test.php reload -f
     * @var bool
     */
    public static $force_delayed_kill = false;
    
    /**
     * 重定向标准输出，即将所有echo、var_dump等终端输出写到对应文件中
     * 注意 此参数只有在以守护进程方式运行时有效
     * @var string
     */
    public static $stdoutFile = '/dev/null';
    
    /**
     * pid文件的路径及名称
     * 例如 Worker::$pidFile = '/tmp/workerclient.pid';
     * 注意 此属性一般不必手动设置，默认会放到php临时目录中
     * @var string
     */
    public static $pidFile = '';
    
    /**
     * 日志目录，默认在workerclient根目录下，与Applications同级
     * 可以手动设置
     * 例如 Worker::$logFile = '/tmp/workerclient.log';
     * @var mixed
     */
    public static $logFile = '';
    
    /**
     * 全局事件轮询库，用于监听所有资源的可读可写事件
     * @var Select/Libevent
     */
    public static $globalEvent = null;
    protected static $receiced_signal = null;
    protected static $delay_signal_installed = false;
    
    /**
     * 主进程pid
     * @var int
     */
    protected static $_masterPid = 0;
    
    /**
     * socket名称，包括应用层协议+ip+端口号，在初始化worker时设置 
     * 值类似 redis://127.0.0.1:6379
     * @var string
     */
    protected $_socketName = '';

    public $scheme  = null;
    public $host    = null;
    public $port    = null;
    public $path    = null;
    
    /**
     * socket的上下文，具体选项设置可以在初始化worker时传递
     * @var array
     */
    protected $_context = null;
    
    /**
     * 所有的worker实例
     * @var array
     */
    protected static $_workers = array();
    
    /**
     * 所有worker进程的pid
     * 格式为 [worker_id=>[pid=>pid, pid=>pid, ..], ..]
     * @var array
     */
    protected static $_pidMap = array();
    
    /**
     * 所有需要重启的进程pid
     * 格式为 [pid=>pid, pid=>pid]
     * @var array
     */
    protected static $_pidsToRestart = array();
    
    /**
     * 所有进程pid到id的映射
     * 格式为[worker_id=>[0=>$pid, 1=>$pid, ..], ..]
     * @var array
     */
    protected static $_idMap = array();
    
    /**
     * 当前worker状态
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;
    
    /**
     * 所有worke名称(name属性)中的最大长度，用于在运行 status 命令时格式化输出
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;
    
    /**
     * 所有socket名称(_socketName属性)中的最大长度，用于在运行 status 命令时格式化输出
     * @var int
     */
    protected static $_maxSocketNameLength = 12;
    
    /**
     * 所有user名称(user属性)中的最大长度，用于在运行 status 命令时格式化输出
     * @var int
     */
    protected static $_maxUserNameLength = 12;
    
    /**
     * 运行 status 命令时用于保存结果的文件名
     * @var string
     */
    protected static $_statisticsFile = '';
    
    /**
     * 启动的全局入口文件
     * 例如 php start.php start ，则入口文件为start.php
     * @var string
     */
    protected static $_startFile = '';
    
    /**
     * 全局统计数据，用于在运行 status 命令时展示
     * 统计的内容包括 workerclient启动的时间戳及每组worker进程的退出次数及退出状态码
     * @var array
     */
    protected static $_globalStatistics = array(
        'start_timestamp' => 0,
        'worker_exit_info' => array()
    );
    
    /**
     * 运行所有worker实例
     * @return void
     */
    public static function runAll()
    {
        // 初始化环境变量
        self::init();
        // 解析命令
        self::parseCommand();
        // 尝试以守护进程模式运行
        self::daemonize();
        // 初始化所有worker实例，主要是监听端口
        self::initWorkers();
        //  初始化所有信号处理函数
        self::installSignal();
        // 保存主进程pid
        self::saveMasterPid();
        // 创建子进程（worker进程）并运行
        self::forkWorkers();
        // 展示启动界面
        self::displayUI();
        // 尝试重定向标准输入输出
        self::resetStd();
        // 监控所有子进程（worker进程）
        self::monitorWorkers();
    }
    
    /**
     * 初始化一些环境变量
     * @return void
     */
    protected static function init()
    {
        // 如果没设置$pidFile，则生成默认值
        if(empty(self::$pidFile))
        {
            $backtrace = debug_backtrace();
            global $argv;
            self::$_startFile = getcwd() . '/' . $argv[0];
            self::$pidFile = self::$_startFile . '.pid';
        }
        // 没有设置日志文件，则生成一个默认值
        if(empty(self::$logFile))
        {
            self::$logFile = self::$_startFile . '.log';
        }
        // 标记状态为启动中
        self::$_status = self::STATUS_STARTING;
        // 启动时间戳
        self::$_globalStatistics['start_timestamp'] = microtime(true);
        // 设置status文件位置
        self::$_statisticsFile = sys_get_temp_dir().'/workerclient.status';
        // 尝试设置进程名称（需要php>=5.5或者安装了proctitle扩展）
        self::setProcessTitle('WorkerClient: master process  start_file=' . self::$_startFile);
        // 初始化id
        self::initId();
        // 初始化定时器
        Timer::init();
    }
    
    /**
     * 初始化所有的worker实例，主要工作为获得格式化所需数据及监听端口
     * @return void
     */
    protected static function initWorkers()
    {
        /** @var static $worker */
        foreach(self::$_workers as $worker)
        {
            // 没有设置worker名称，则使用none代替
            if(empty($worker->name))
            {
                $worker->name = basename(self::$_startFile);
            }
            // 获得所有worker名称中最大长度
            $worker_name_length = strlen($worker->name);
            if(self::$_maxWorkerNameLength < $worker_name_length)
            {
                self::$_maxWorkerNameLength = $worker_name_length;
            }
            // 获得所有_socketName中最大长度
            $socket_name_length = strlen($worker->getSocketName());
            if(self::$_maxSocketNameLength < $socket_name_length)
            {
                self::$_maxSocketNameLength = $socket_name_length;
            }
            // 获得运行用户名的最大长度
            if(empty($worker->user) || posix_getuid() !== 0)
            {
                $worker->user = self::getCurrentUser();
            }
            $user_name_length = strlen($worker->user);
            if(self::$_maxUserNameLength < $user_name_length)
            {
                self::$_maxUserNameLength = $user_name_length;
            }
        }
    }
    
    /**
     * 初始化idMap
     * return void
     */
    protected static function initId()
    {
        foreach(self::$_workers as $worker_id=>$worker)
        {
            self::$_idMap[$worker_id] = array_fill(0, $worker->count, 0);
        }
    }
    
    /**
     * 获得运行当前进程的用户名
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }
    
    /**
     * 展示启动界面
     * @return void
     */
    protected static function displayUI()
    {
        echo "\033[1A\n\033[K-----------------------\033[47;30m WORKERCLIENT \033[0m-----------------------------\n\033[0m";
        echo 'WorkerClient version:' , Worker::VERSION , "          PHP version:",PHP_VERSION,"\n";
        echo "------------------------\033[47;30m WORKERS \033[0m-------------------------------\n";
        echo "\033[47;30muser\033[0m",
            str_pad('', self::$_maxUserNameLength+2-strlen('user')),
            "\033[47;30mworker\033[0m",
            str_pad('', self::$_maxWorkerNameLength+2-strlen('worker')),
            "\033[47;30mlisten\033[0m",str_pad('', self::$_maxSocketNameLength+2-strlen('listen')),
            "\033[47;30mprocesses\033[0m \033[47;30m","status\033[0m\n";
        /** @var static $worker */
        foreach(self::$_workers as $worker)
        {
            echo str_pad($worker->user, self::$_maxUserNameLength+2),
                str_pad($worker->name, self::$_maxWorkerNameLength+2),
                str_pad($worker->getSocketName(), self::$_maxSocketNameLength+2),
                str_pad(' '.$worker->count, 9),
                " \033[32;40m [OK] \033[0m\n";;
        }
        echo "----------------------------------------------------------------\n";
        if(self::$daemonize)
        {
            global $argv;
            $start_file = $argv[0];
            echo "Input \"php $start_file stop\" to quit. Start success.\n";
        }
        else
        {
            echo "Press Ctrl-C to quit. Start success.\n";
        }
    }
    
    /**
     * 解析运行命令
     * php yourfile.php start | stop | restart | reload | status
     * @return void
     */
    protected static function parseCommand()
    {
        // 检查运行命令的参数
        global $argv;
        $start_file = $argv[0]; 
        if(!isset($argv[1]))
        {
            exit("Usage: php yourfile.php {start|stop|restart|reload|status|kill}\n");
        }
        
        // 命令
        $command = trim($argv[1]);
        
        // 子命令，目前只支持-d -f
        $command2 = isset($argv[2]) ? $argv[2] : '';
        
        // 记录日志
        $mode = '';
        if($command === 'start')
        {
            if($command2 === '-d')
            {
                $mode = 'in DAEMON mode';
            }
            else
            {
                $mode = 'in DEBUG mode';
            }
        }
        self::log("WorkerClient[$start_file] $command $mode");
        
        // 检查主进程是否在运行
        $master_pid = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                self::log("WorkerClient[$start_file] already running");
                exit;
            }
            else if ($command2 === '-f')
            {
                //向主进程发送SIGHUP信号，启用强制退出
                self::$force_delayed_kill = true;
                self::log("WorkerClient[$start_file] turn on force_delayed_kill");
                $master_is_alive && posix_kill($master_pid, SIGHUP);
            }
        }
        elseif($command !== 'start' && $command !== 'restart')
        {
            self::log("WorkerClient[$start_file] not running");
        }
        
        // 根据命令做相应处理
        switch($command)
        {
            case 'kill':
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}' |xargs kill -SIGINT");
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}' |xargs kill -SIGKILL");
                break;
            // 启动 workerclient
            case 'start':
                if($command2 === '-d')
                {
                    Worker::$daemonize = true;
                }
                break;
            // 显示 workerclient 运行状态
            case 'status':
                // 尝试删除统计文件，避免脏数据
                if(is_file(self::$_statisticsFile))
                {
                    @unlink(self::$_statisticsFile);
                }
                // 向主进程发送 SIGUSR2 信号 ，然后主进程会向所有子进程发送 SIGUSR2 信号
                // 所有进程收到 SIGUSR2 信号后会向 $_statisticsFile 写入自己的状态
                posix_kill($master_pid, SIGUSR2);
                // 睡眠100毫秒，等待子进程将自己的状态写入$_statisticsFile指定的文件
                usleep(100000);
                // 展示状态
                readfile(self::$_statisticsFile);
                exit(0);
            // 重启 workerclient
            case 'restart':
            // 停止 workeran
            case 'stop':
                self::log("WorkerClient[$start_file] is stopping ...");
                // 向主进程发送SIGINT信号，主进程会向所有子进程发送SIGINT信号
                $master_pid && posix_kill($master_pid, SIGINT);
                // 如果 $timeout 秒后主进程没有退出则展示失败界面
                $timeout = 5;
                $start_time = time();
                while(1)
                {
                    // 检查主进程是否存活
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if($master_is_alive)
                    {
                        // 检查是否超过$timeout时间
                        if(time() - $start_time >= $timeout)
                        {
                            self::log("WorkerClient[$start_file] stop worker instantly fail");
                            if (self::$force_delayed_kill)
                            {
                                self::log("WorkerClient[$start_file] SIGKILL will be sent to alive workers.");
                            }
                            exit;
                        }
                        usleep(10000);
                        continue;
                    }
                    self::log("WorkerClient[$start_file] stop success");
                    // 是restart命令
                    if($command === 'stop')
                    {
                        exit(0);
                    }
                    // -d 说明是以守护进程的方式启动
                    if($command2 === '-d')
                    {
                        Worker::$daemonize = true;
                    }
                    break;
                }
                break;
            // 平滑重启 workerclient
            case 'reload':
                posix_kill($master_pid, SIGUSR1);
                self::log("WorkerClient[$start_file] reload");
                exit;
            // 未知命令
            default :
                 exit("Usage: php yourfile.php {start|stop|restart|reload|status|kill}\n");
        }
    }
    
    /**
     * 安装信号处理函数
     * @return void
     */
    protected static function installSignal()
    {
        // force_delayed_kill
        pcntl_signal(SIGHUP,  array('\WorkerClient\Worker', 'signalHandler'), false);
        // stop
        pcntl_signal(SIGINT,  array('\WorkerClient\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\WorkerClient\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\WorkerClient\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }
    
    /**
     * 为子进程重新安装信号处理函数，使用全局事件轮询监听信号
     * @return void
     */
    protected static function reinstallSignal()
    {
        // uninstall force signal handler
        pcntl_signal(SIGHUP,  SIG_IGN, false);
        // uninstall stop signal handler
        pcntl_signal(SIGINT,  SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall  status signal handler
        pcntl_signal(SIGUSR2, SIG_IGN, false);

        // reinstall force signal handler
        self::$globalEvent->add(SIGHUP, EventInterface::EV_SIGNAL, array('\WorkerClient\Worker', 'signalHandler'));
        // reinstall stop signal handler
        self::$globalEvent->add(SIGINT, EventInterface::EV_SIGNAL, array('\WorkerClient\Worker', 'signalHandler'));
        //  uninstall  reload signal handler
        self::$globalEvent->add(SIGUSR1, EventInterface::EV_SIGNAL,array('\WorkerClient\Worker', 'signalHandler'));
        // uninstall  status signal handler
        self::$globalEvent->add(SIGUSR2, EventInterface::EV_SIGNAL, array('\WorkerClient\Worker', 'signalHandler'));
    }
    
    /**
     * 信号处理函数
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        self::$receiced_signal = $signal;
        switch($signal)
        {
            case SIGHUP:
                self::$force_delayed_kill = true;
                break;
            // stop
            case SIGINT:
                self::stopAll();
                break;
            // reload
            case SIGUSR1:
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
            // show status
            case SIGUSR2:
                self::writeStatisticsToStatusFile();
                break;
        }
    }

    /**
     * 尝试以守护进程的方式运行
     * @throws Exception
     */
    protected static function daemonize()
    {
        if(!self::$daemonize)
        {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if(-1 === $pid)
        {
            throw new Exception('fork fail');
        }
        elseif($pid > 0)
        {
            exit(0);
        }
        if(-1 === posix_setsid())
        {
            throw new Exception("setsid fail");
        }
        // fork again avoid SVR4 system regain the control of terminal
        $pid = pcntl_fork();
        if(-1 === $pid)
        {
            throw new Exception("fork fail");
        }
        elseif(0 !== $pid)
        {
            exit(0);
        }
    }

    /**
     * 重定向标准输入输出
     * @throws Exception
     */
    protected static function resetStd()
    {
        if(!self::$daemonize)
        {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile,"a");
        if($handle) 
        {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile,"a");
            $STDERR = fopen(self::$stdoutFile,"a");
        }
        else
        {
            throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }
    
    /**
     * 保存pid到文件中，方便运行命令时查找主进程pid
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        self::$_masterPid = posix_getpid();
        if(false === @file_put_contents(self::$pidFile, self::$_masterPid))
        {
            throw new Exception('can not save pid to ' . self::$pidFile);
        }
    }
    
    /**
     * 获得所有子进程的pid
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach(self::$_pidMap as $worker_pid_array)
        {
            foreach($worker_pid_array as $worker_pid)
            {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * 创建子进程
     * @return void
     */
    protected static function forkWorkers()
    {
        /** @var static $worker */
        foreach(self::$_workers as $worker)
        {
            // 启动过程中需要得到运行用户名的最大长度，在status时格式化展示
            if(self::$_status === self::STATUS_STARTING)
            {
                if(empty($worker->name))
                {
                    $worker->name = basename(self::$_startFile);
                }
                $worker_name_length = strlen($worker->name);
                if(self::$_maxWorkerNameLength < $worker_name_length)
                {
                    self::$_maxWorkerNameLength = $worker_name_length;
                }
            }
            
            // 创建子进程
            while(count(self::$_pidMap[$worker->workerId]) < $worker->count)
            {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     * 创建一个子进程
     * @param Worker $worker
     * @throws Exception
     */
    protected static function forkOneWorker($worker)
    {
        $pid = pcntl_fork();
        // 获得可用的id
        $id = self::getId($worker->workerId, 0);
        // 主进程记录子进程pid
        if($pid > 0)
        {
            self::$_pidMap[$worker->workerId][$pid] = $pid;
            self::$_idMap[$worker->workerId][$id] = $pid;
        }
        // 子进程运行
        elseif(0 === $pid)
        {
            // 设置自动加载根目录
            Autoloader::setRootPath($worker->_appInitPath);

            // 启动过程中尝试重定向标准输出
            if(self::$_status === self::STATUS_STARTING)
            {
                self::resetStd();
            }
            self::$_pidMap = array();
            self::$_workers = array($worker->workerId => $worker);
            Timer::delAll();
            self::setProcessTitle('WorkerClient: worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            self::setProcessUser($worker->user);
            $worker->id = $id;
            $worker->run();
            exit(250);
        }
        else
        {
            throw new Exception("forkOneWorker fail");
        }
    }
    
    /**
     * 获得可用的worker->id，以便传递给子进程
     * @param int $worker_id
     * @param int $pid
     */
    protected static function getId($worker_id, $pid)
    {
        $id = array_search($pid, self::$_idMap[$worker_id]);
        if($id === false)
        {
            echo "getId fail\n";
        }
        return $id;
    }

    /**
     * 尝试设置运行当前进程的用户
     *
     * @param $user_name
     */
    protected static function setProcessUser($user_name)
    {
        if(empty($user_name) || posix_getuid() !== 0)
        {
            return;
        }
        $user_info = posix_getpwnam($user_name);
        if($user_info['uid'] != posix_getuid() || $user_info['gid'] != posix_getgid())
        {
            if(!posix_setgid($user_info['gid']) || !posix_setuid($user_info['uid']))
            {
                self::log( 'Notice : Can not run woker as '.$user_name." , You should be root\n", true);
            }
        }
    }

    
    /**
     * 设置当前进程的名称，在ps aux命令中有用
     * 注意 需要php>=5.5或者安装了protitle扩展
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title'))
        {
            @cli_set_process_title($title);
        }
        // 需要扩展
        elseif(extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            @setproctitle($title);
        }
    }
    
    /**
     * 监控所有子进程的退出事件及退出码
     * @return void
     */
    protected static function monitorWorkers()
    {
        self::$_status = self::STATUS_RUNNING;
        while(1)
        {
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            // 挂起进程，直到有子进程退出或者被信号打断
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            // 有子进程退出
            if($pid > 0)
            {
                // 查找是哪个进程组的，然后再启动新的进程补上
                foreach(self::$_pidMap as $worker_id => $worker_pid_array)
                {
                    if(isset($worker_pid_array[$pid]))
                    {
                        $worker = self::$_workers[$worker_id];
                        // 检查退出状态
                        if($status !== 0)
                        {
                            self::log("worker[".$worker->name.":$pid] exit with status $status");
                        }
                       
                        // 统计，运行status命令时使用
                        if(!isset(self::$_globalStatistics['worker_exit_info'][$worker_id][$status]))
                        {
                            self::$_globalStatistics['worker_exit_info'][$worker_id][$status] = 0;
                        }
                        self::$_globalStatistics['worker_exit_info'][$worker_id][$status]++;
                        
                        // 清除子进程信息
                        unset(self::$_pidMap[$worker_id][$pid]);
                        
                        // 标记$id为可用id
                        $id = self::getId($worker_id, $pid);
                        self::$_idMap[$worker_id][$id] = 0;
                        
                        break;
                    }
                }
                // 如果不是关闭状态，则补充新的进程
                if(self::$_status !== self::STATUS_SHUTDOWN)
                {
                    self::forkWorkers();
                    // 如果该进程是因为运行reload命令退出，则继续执行reload流程
                    if(isset(self::$_pidsToRestart[$pid]))
                    {
                        unset(self::$_pidsToRestart[$pid]);
                        self::reload();
                    }
                }
                else
                {
                    // 如果是关闭状态，并且所有进程退出完毕，则主进程退出
                    if(!self::getAllWorkerPids())
                    {
                        self::exitAndClearAll();
                    }
                }
            }
            else 
            {
                // 如果是关闭状态，并且所有进程退出完毕，则主进程退出
                if(self::$_status === self::STATUS_SHUTDOWN && !self::getAllWorkerPids())
                {
                   self::exitAndClearAll();
                }
            }
        }
    }
    
    /**
     * 退出当前进程
     * @return void
     */
    protected static function exitAndClearAll()
    {
        @unlink(self::$pidFile);
        $seconds = self::getDuration(true);
        self::log("WorkerClient[".basename(self::$_startFile)."] has been stopped, time: {$seconds} seconds.");
        exit(0);
    }
    
    /**
     * 执行平滑重启流程
     * @return void
     */
    protected static function reload()
    {
        // 主进程部分
        if(self::$_masterPid === posix_getpid())
        {
            // 设置为平滑重启状态
            if(self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN)
            {
                self::log("WorkerClient[".basename(self::$_startFile)."] reloading");
                self::$_status = self::STATUS_RELOADING;
            }
            
            // 如果有worker设置了reloadable=false，则过滤掉
            $reloadable_pid_array = array();
            foreach(self::$_pidMap as $worker_id =>$worker_pid_array)
            {
                $worker = self::$_workers[$worker_id];
                if($worker->reloadable)
                {
                    foreach($worker_pid_array as $pid)
                    {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                }
                else
                {
                    foreach($worker_pid_array as $pid)
                    {
                        // 给reloadable=false的进程也发送一个reload信号，触发onWorkerReload
                        posix_kill($pid, SIGUSR1);
                    }
                }
            }
            
            // 得到所有可以重启的进程
            self::$_pidsToRestart = array_intersect(self::$_pidsToRestart , $reloadable_pid_array);
            
            // 平滑重启完毕
            if(empty(self::$_pidsToRestart))
            {
                if(self::$_status !== self::STATUS_SHUTDOWN)
                {
                    self::$_status = self::STATUS_RUNNING;
                }
                return;
            }
            // 继续执行平滑重启流程
            $one_worker_pid = current(self::$_pidsToRestart );
            // 给子进程发送平滑重启信号
            self::killWorker($one_worker_pid, SIGUSR1);
        }
        // 子进程部分
        else
        {
            // 如果当前worker的reloadable属性为真，则执行退出
            $worker = current(self::$_workers);
            if ($worker)
            {
                $pid = posix_getpid();
                echo "{$pid} onWorkerReload\n";
                // 如果有设置Reload回调，则执行
                if($worker->onWorkerReload)
                {
                    call_user_func($worker->onWorkerReload, $worker);
                }
                if($worker->reloadable)
                {
                    self::stopAll();
                }
            }
        }
    }
    
    /*
     * 给pid发送指定信号
     * 如果有需要，延时发送 SIGKILL
     */
    protected static function killWorker($pid, $signal)
    {
        posix_kill($pid, $signal);
        if (self::$force_delayed_kill)
        {
            // 定时器，如果子进程在KILL_WORKER_TIMER_TIME秒后没有退出，则强行杀死
            Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($pid, SIGKILL), false);
        }
    }

    /**
     * 执行关闭流程
     * @return void
     */
    public static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // 主进程部分
        if(self::$_masterPid === posix_getpid())
        {
            self::log("WorkerClient[".basename(self::$_startFile)."] Stopping ...");
            $worker_pid_array = self::getAllWorkerPids();
            // 向所有子进程发送SIGINT信号，表明关闭服务
            foreach($worker_pid_array as $worker_pid)
            {
                self::killWorker($worker_pid, SIGINT);
            }
        }
        // 子进程部分
        else
        {
            // 执行stop逻辑
            /** @var static $worker */
            $safe_exit = true;
            foreach (self::$_workers as $worker) {
                if ($worker->connection and $worker->connection->getStatus() !== TcpConnection::STATUS_CLOSED)
                {
                    $safe_exit = false;
                }
                else
                {
                    $worker->stop(self::DELAY_EXIT);
                }
            }

            if ($safe_exit)
            {
                exit(0);
            }
            else //不想放弃治疗
            {
                $pid = posix_getpid();
                self::log("WorkerClient[{$pid}] is still working, exit delayed.");
                if (self::$delay_signal_installed == false) {
                    Timer::add(self::EXIT_RETRY_DELAY, 'posix_kill', array($pid, self::$receiced_signal), true);
                    self::$delay_signal_installed = true;
                }
            }
        }
    }
    
    /**
     * 将当前进程的统计信息写入到统计文件
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // 主进程部分
        if(self::$_masterPid === posix_getpid())
        {
            $loadavg = sys_getloadavg();
            file_put_contents(self::$_statisticsFile, "---------------------------------------GLOBAL STATUS--------------------------------------------\n");
            file_put_contents(self::$_statisticsFile, 'WorkerClient version:' . Worker::VERSION . "          PHP version:".PHP_VERSION."\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, 'start time:'. date('Y-m-d H:i:s', intval(self::$_globalStatistics['start_timestamp'])).'   run ' . floor(self::getDuration()/(24*60*60)). ' days ' . floor((self::getDuration()%(24*60*60))/(60*60)) . " hours   \n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, 'load average: ' . implode(", ", $loadavg) . "\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile,  count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids())." processes\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, str_pad('worker_name', self::$_maxWorkerNameLength) . " exit_status     exit_count\n", FILE_APPEND);
            foreach(self::$_pidMap as $worker_id =>$worker_pid_array)
            {
                $worker = self::$_workers[$worker_id];
                if(isset(self::$_globalStatistics['worker_exit_info'][$worker_id]))
                {
                    foreach(self::$_globalStatistics['worker_exit_info'][$worker_id] as $worker_exit_status=>$worker_exit_count)
                    {
                        file_put_contents(self::$_statisticsFile, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status, 16). " $worker_exit_count\n", FILE_APPEND);
                    }
                }
                else
                {
                    file_put_contents(self::$_statisticsFile, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad(0, 16). " 0\n", FILE_APPEND);
                }
            }
            file_put_contents(self::$_statisticsFile,  "---------------------------------------PROCESS STATUS-------------------------------------------\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, "pid\tmemory  ".str_pad('watching', self::$_maxSocketNameLength)." ".str_pad('worker_name', self::$_maxWorkerNameLength)." connections ".str_pad('total_request', 13)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)."\n", FILE_APPEND);
            
            chmod(self::$_statisticsFile, 0722);
            
            foreach(self::getAllWorkerPids() as $worker_pid)
            {
                posix_kill($worker_pid, SIGUSR2);
            }
            return;
        }
        
        // 子进程部分
        $worker = current(self::$_workers);
        $wrker_status_str = posix_getpid() . "\t"
            .str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7) . " "
            .str_pad($worker->getSocketName(), self::$_maxSocketNameLength) . " "
            .str_pad($worker->name, self::$_maxWorkerNameLength)." "
            .str_pad(ConnectionInterface::$statistics['connection_count'], 11) . " "
            .str_pad(ConnectionInterface::$statistics['total_request'], 14) . " "
            .str_pad(ConnectionInterface::$statistics['send_fail'],9) . " "
            .str_pad(ConnectionInterface::$statistics['throw_exception'], 15) . "\n";
        file_put_contents(self::$_statisticsFile, $wrker_status_str, FILE_APPEND);
    }
    
    /**
     * 检查错误
     * @return void
     */
    public static function checkErrors()
    {
        if(self::STATUS_SHUTDOWN != self::$_status)
        {
            $error_msg = "WORKER EXIT UNEXPECTED ";
            $errors = error_get_last();
            if($errors && ($errors['type'] === E_ERROR ||
                     $errors['type'] === E_PARSE ||
                     $errors['type'] === E_CORE_ERROR ||
                     $errors['type'] === E_COMPILE_ERROR || 
                     $errors['type'] === E_RECOVERABLE_ERROR ))
            {
                $error_msg .= self::getErrorType($errors['type']) . " {$errors['message']} in {$errors['file']} on line {$errors['line']}";
            }
            self::log($error_msg);
        }
    }
    
    /**
     * 获取错误类型对应的意义
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch($type)
        {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }
    
    /**
     * 记录日志
     * @param string $msg
     * @return void
     */
    protected static function log($msg)
    {
        $msg = $msg."\n";
        if(!self::$daemonize)
        {
            echo $msg;
        }
        file_put_contents(self::$logFile, date('Y-m-d H:i:s') . " " . $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * worker构造函数
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', $context_option = array())
    {
        // 保存worker实例
        $this->workerId = spl_object_hash($this);
        self::$_workers[$this->workerId] = $this;
        self::$_pidMap[$this->workerId] = array();
        
        // 获得实例化文件路径，用于自动加载设置根目录
        $backrace = debug_backtrace();
        $this->_appInitPath = dirname($backrace[0]['file']);
        
        // 设置socket上下文
        if($socket_name)
        {
            $this->_socketName = $socket_name;
            if(!isset($context_option['socket']['backlog']))
            {
                $context_option['socket']['backlog'] = self::DEFAUL_BACKLOG;
            }
            $this->_context = stream_context_create($context_option);
        }
        
        // 设置一个空的onMessage，当onMessage未设置时用来消费socket数据
        $this->onMessage = function(){};
    }
    
    /**
     * 连接服务器
     * @throws Exception
     */
    public function connect()
    {
        // 获得应用层通讯协议以及目标服务器的地址
        $url = parse_url($this->_socketName);
        if (false === $url) {
            throw new Exception("invalid socketName");
        }

        // 如果有指定应用层协议，则检查对应的协议类是否存在
        $scheme = @$url['scheme'];
        if($scheme !== 'tcp')
        {
            $scheme = ucfirst($scheme);
            $this->_protocol = '\\Protocols\\'.$scheme;
            if(!class_exists($this->_protocol))
            {
                $this->_protocol = "\\WorkerClient\\Protocols\\$scheme";
                if(!class_exists($this->_protocol))
                {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }

        // 创建异步连接
        $address = @$url['host'] . ':' . @$url['port'];
        $socket = @stream_socket_client("tcp://{$address}", $errno, $errstr);
        if(!$socket)
        {
            sleep(1); //避免不间断重练
            throw new Exception("connect to server failed");
        }

        // 尝试打开tcp的keepalive，关闭TCP Nagle算法
        if(function_exists('socket_import_stream'))
        {
            $_socket   = socket_import_stream($socket);
            @socket_set_option($_socket, SOL_SOCKET, SO_KEEPALIVE, 1);
            @socket_set_option($_socket, SOL_SOCKET, TCP_NODELAY, 1);
        }

        // 设置非阻塞
        stream_set_blocking($socket, 0);

        $connection = new TcpConnection($socket, $address);
        $this->connection = $connection;
        
        $connection->worker = $this;
        $connection->protocol = $this->_protocol;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        $connection->onBufferDrain = $this->onBufferDrain;
        $connection->onBufferFull = $this->onBufferFull;

        // 如果有设置连接回调，则执行
        if($this->onConnect)
        {
            try
            {
                call_user_func($this->onConnect, $connection);
            }
            catch(Exception $e)
            {
                ConnectionInterface::$statistics['throw_exception']++;
                self::log($e);
            }
        }
    }
    
    /**
     * 获得 socket name
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? $this->_socketName : 'none';
    }
    
    /**
     * 运行worker实例
     */
    public function run()
    {
        //更新 Worker 状态
        self::$_status = self::STATUS_RUNNING;
        
        // 注册进程退出回调，用来检查是否有错误
        register_shutdown_function(array("\\WorkerClient\\Worker", 'checkErrors'));
        
        // 设置自动加载根目录
        Autoloader::setRootPath($this->_appInitPath);
        
        // 如果没有全局事件轮询，则创建一个
        if(!self::$globalEvent)
        {
            if(extension_loaded('libevent'))
            {
                self::$globalEvent = new Libevent();
            }
            else
            {
                self::$globalEvent = new Select();
            }
            $this->connect();
        }
        
        // 重新安装事件处理函数，使用全局事件轮询监听信号事件
        self::reinstallSignal();
        
        // 用全局事件轮询初始化定时器
        Timer::init(self::$globalEvent);
        
        // 如果有设置进程启动回调，则执行
        if($this->onWorkerStart)
        {
            call_user_func($this->onWorkerStart, $this);
        }
        
        // 子进程主循环
        self::$globalEvent->loop();
    }
    
    /**
     * 停止当前worker实例
     * @return void
     */
    public function stop($exit_instantly = true)
    {
        // 如果有设置进程终止回调，则执行
        if($this->onWorkerStop)
        {
            call_user_func($this->onWorkerStop, $this);
        }

        if ($exit_instantly)
        {
            exit(0);
        }
    }

    public static function getDuration($accurate=false)
    {
        $seconds = microtime(true) - self::$_globalStatistics['start_timestamp'];
        if (!$accurate) {
            $seconds = intval($seconds);
        }
        else {
            $seconds = round($seconds, 6);
        }
        return $seconds;
    }

    public static function getStatus()
    {
        return self::$_status;
    }
}
