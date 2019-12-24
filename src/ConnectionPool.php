<?php

namespace Swover\Pool;


use Swover\Pool\Handler\Channel;
use Swover\Pool\Handler\PoolHandler;
use Swover\Pool\Handler\SplQueue;

class ConnectionPool
{
    /**
     * @var int
     */
    private $minSize = 1;

    /**
     * @var int
     */
    private $maxSize = 10;

    /**
     * @var int
     */
    private $waitTime = 3;

    /**
     * @var int
     */
    private $idleTime = 120;

    /**
     * @var ConnectorInterface
     */
    private $connector;

    /**
     * Number of connections
     *
     * @var int
     */
    private $connectionCount = 0;

    /**
     * @var PoolHandler
     */
    private $pool;

    /**
     * @var array
     */
    private $config = [];

    /**
     * ConnectionPool constructor.
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     */
    public function __construct(array $poolConfig, ConnectorInterface $connector)
    {
        $this->config = $poolConfig;

        $this->minSize = $poolConfig['minSize'] ?? $this->minSize;
        $this->maxSize = $poolConfig['maxSize'] ?? $this->maxSize;
        $this->waitTime = $poolConfig['waitTime'] ?? $this->waitTime;
        $this->idleTime = $poolConfig['idleTime'] ?? $this->idleTime;

        $this->connector = $connector;

        $this->initPool();
    }

    protected function initPool()
    {
        $poolHandler = 'normal';

        if (class_exists('\Swoole\Coroutine') && class_exists('\Swoole\Channel')) {
            if (method_exists('\Swoole\Coroutine', 'getCid') && \Swoole\Coroutine::getCid() > 0) {
                $poolHandler = 'channel';
            }
        }

        $poolHandler = $this->config['poolHandler'] ?? $poolHandler;

        if (is_object($poolHandler) && $poolHandler instanceof PoolHandler) {
            return $this->pool = $poolHandler;
        }

        if ($poolHandler == 'channel') {
            return $this->pool = new Channel($this->maxSize);
        }
        return $this->pool = new SplQueue($this->maxSize);
    }

    /**
     * Get active connection from pool
     *
     * @return mixed
     * @throws \Exception
     */
    public function getConnection()
    {
        if ($this->connectionCount < $this->minSize
            && $this->pool->isEmpty()) {
            return $this->createConnection();
        }

        $connection = $this->pool->pop($this->waitTime);
        if ($connection === false) {
            if ($this->connectionCount < $this->maxSize) {
                return $this->createConnection();
            }
            throw new \Exception(sprintf('connection pop timeout, waitTime:%d, all connections: %d',
                $this->waitTime, $this->connectionCount));
        }

        if (time() - $connection['active_time'] >= $this->idleTime
            && !$this->pool->isEmpty()) {
            $this->removeConnection($connection['instance']);
            return $this->getConnection();
        }

        return $connection['instance'];
    }

    /**
     * Create a new connection
     * @return mixed
     */
    public function createConnection()
    {
        $this->connectionCount++;
        $connection = $this->connector->connect();
        return $connection;
    }

    /**
     * Release a connection to pool, and update last active time
     * @param $connection
     * @return bool
     */
    public function releaseConnection($connection)
    {
        if ($this->pool->isFull()) {
            $this->removeConnection($connection);
            return false;
        }

        if ($this->connectionCount > $this->minSize && !$this->pool->isEmpty()) {
            $this->removeConnection($connection);
            return false;
        }

        $connector = [
            'active_time' => time(),
            'instance' => $connection
        ];
        if ($this->pool->push($connector, 0.001) === false) {
            $this->removeConnection($connection);
            return false;
        }
        return true;
    }

    /**
     * Remove and disconnect a connection
     * @param $connection
     */
    public function removeConnection($connection)
    {
        $this->connectionCount--;
        try {
            $this->connector->disconnect($connection);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Remove all connection and close pool
     * @return bool
     */
    public function closeConnectionPool()
    {
        while (true) {
            if ($this->pool->isEmpty()
                && $this->connectionCount <= 0) {
                break;
            }
            $connection = $this->pool->pop(0.001);
            if ($connection !== false) {
                $this->removeConnection($connection);
            }
        }
        $this->pool->close();
        return true;
    }

    public function __destruct()
    {
        $this->closeConnectionPool();
    }

    public function __call($name, $arguments)
    {
        $connection = null;
        try {
            $connection = $this->getConnection();
            return call_user_func_array([$connection, $name], $arguments);
        } catch (\Throwable $e) {
            if (isset($this->config['failCallback']) && is_callable($this->config['failCallback']) ) {
                $res = call_user_func_array($this->config['failCallback'], [$connection, $e]);
            } else {
                $res = $this->connector->ping($connection);
            }

            if ($res !== true) {
                $this->removeConnection($connection);
                $connection = null;
            }

            try {
                $connection = $connection ? : $this->getConnection();
                return call_user_func_array([$connection, $name], $arguments);
            } catch (\Throwable $e) {
                $res = $this->connector->ping($connection);
                if ($res !== true) {
                    $this->removeConnection($connection);
                    $connection = null;
                }
                throw $e;
            }
        } finally {
            if ($connection != null) {
                $this->releaseConnection($connection);
            }
        }
    }
}
