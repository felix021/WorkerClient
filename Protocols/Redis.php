<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace WorkerClient\Protocols;
use \WorkerClient\Connection\TcpConnection;

/**
 * Redis协议
 * 以换行为请求结束标记
 * @author felix021 <felix021@gmail.com>
 */
class Redis
{
    /**
     * 检查包的完整性
     * 如果能够得到包长，则返回包的长度，否则返回0继续等待数据
     * @param string $buffer
     */
    public static function input($buffer, TcpConnection $connection)
    {
        $lines = explode("\n", $buffer);
        if (count($lines) < 5) {
            return 0;
        }
        return strlen(join("\n", $lines));
    }
    
    /**
     * 打包，当向客户端发送数据的时候会自动调用
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        return $buffer;
    }
    
    /**
     * 解包，当接收到的数据字节数等于input返回的值（大于0的值）自动调用
     * 并传递给onMessage回调函数的$data参数
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        $lines = explode("\n", $buffer);
        $data_len = intval(substr($lines[3], 1));
        return substr($lines[4], 0, $data_len);
    }
}
