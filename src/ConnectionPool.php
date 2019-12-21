<?php

namespace Swover\Pool;


use Swover\Pool\Handler\Channel;
use Swover\Pool\Handler\PoolType;
use Swover\Pool\Handler\SplQueue;

class ConnectionPool
{
    /**
     * @var int
     */
    private $minSize = 3;

    /**
     * @var int
     */
    private $maxSize = 50;

    /**
     * @var int
     */
    private $waitTime = 5;

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
     * @var PoolType
     */
    private $pool;

    /**
     * ConnectionPool constructor.
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     */
    public function __construct(array $poolConfig, ConnectorInterface $connector)
    {
        $this->minSize = $poolConfig['minSize'] ?? 1;
        $this->maxSize = $poolConfig['maxSize'] ?? 10;
        $this->waitTime = $poolConfig['waitTime'] ?? 5;
        $this->idleTime = $poolConfig['idleTime'] ?? 120;

        $this->connector = $connector;

        $this->initPool();
    }

    protected function initPool()
    {
        $poolType = 'normal';

        if (class_exists('\Swoole\Coroutine') && class_exists('\Swoole\Channel')) {
            if (method_exists('\Swoole\Coroutine', 'getCid') && \Swoole\Coroutine::getCid() > 0) {
                $poolType = 'channel';
            }
        }

        if (($poolConfig['pool_type'] ?? $poolType) == 'channel') {
            $this->pool = new Channel($this->maxSize);
        } else {
            $this->pool = new SplQueue($this->maxSize);
        }
    }

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

    public function createConnection()
    {
        $this->connectionCount++;
        $connection = $this->connector->connect();
        return $connection;
    }

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

    public function removeConnection($connection)
    {
        $this->connectionCount--;
        // go(function () use ($connection) {
            try {
                $this->connector->disconnect($connection);
            } catch (\Throwable $e) {
            }
        // });
    }

    public function closeConnectionPool()
    {
        // go(function () {
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
        // });
        return true;
    }

    public function __destruct()
    {
        $this->closeConnectionPool();
    }
}
