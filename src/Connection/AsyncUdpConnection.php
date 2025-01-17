<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Connection;

use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * AsyncUdpConnection.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var callable
     */
    public $onConnect = null;

    /**
     * Emitted when socket connection closed.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Connected or not.
     *
     * @var bool
     */
    protected $connected = false;

    /**
     * Context option.
     *
     * @var array
     */
    protected $_contextOption = null;

    /**
     * Construct.
     *
     * @param string $remote_address
     * @throws Exception
     */
    public function __construct($remote_address, $context_option = null)
    {
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $remote_address, 2);
        // Check application layer protocol class.
        if ($scheme !== 'udp') {
            $scheme = \ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }

        $this->_remoteAddress = \substr($address, 2);
        $this->_contextOption = $context_option;
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function baseRead($socket)
    {
        $recv_buffer = \stream_socket_recvfrom($socket, Worker::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }

        if ($this->onMessage) {
            if ($this->protocol) {
                $recv_buffer = $this->protocol::decode($recv_buffer, $this);
            }
            ++ConnectionInterface::$statistics['total_request'];
            try {
                ($this->onMessage)($this, $recv_buffer);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }
        if ($this->connected === false) {
            $this->connect();
        }
        return \strlen($send_buffer) === \stream_socket_sendto($this->_socket, $send_buffer, 0);
    }


    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     *
     * @return bool
     */
    public function close($data = null, $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        Worker::$globalEvent->offReadable($this->_socket);
        \fclose($this->_socket);
        $this->connected = false;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }
        $this->onConnect = $this->onMessage = $this->onClose = null;
        return true;
    }

    /**
     * Connect.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->connected === true) {
            return;
        }
        if ($this->_contextOption) {
            $context = \stream_context_create($this->_contextOption);
            $this->_socket = \stream_socket_client("udp://{$this->_remoteAddress}", $errno, $errmsg,
                30, \STREAM_CLIENT_CONNECT, $context);
        } else {
            $this->_socket = \stream_socket_client("udp://{$this->_remoteAddress}", $errno, $errmsg);
        }

        if (!$this->_socket) {
            Worker::safeEcho(new \Exception($errmsg));
            return;
        }

        \stream_set_blocking($this->_socket, false);

        if ($this->onMessage) {
            Worker::$globalEvent->onWritable($this->_socket, [$this, 'baseRead']);
        }
        $this->connected = true;
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($this);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }
    }

}
