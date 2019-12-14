<?php

namespace Swover\Pool;


use Swoole\Coroutine\Channel;

class ConnectionPool
{
    const CHANNEL_TIMEOUT = 0.001;

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
     * @var Channel
     */
    private $pool;

    /**
     * @var Channel
     */
    private $releaseLock;

    /**
     * ConnectionPool constructor.
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     */
    public function __construct(array $poolConfig, ConnectorInterface $connector)
    {
        $this->minSize = $poolConfig['minSize'] ?? 3;
        $this->maxSize = $poolConfig['maxSize'] ?? 50;
        $this->waitTime = $poolConfig['waitTime'] ?? 5;
        $this->idleTime = $poolConfig['idleTime'] ?? 120;

        $this->connector = $connector;

        $this->pool = new Channel($this->maxSize);
        $this->releaseLock = new Channel(1);
    }

    public function getConnection()
    {
        if ($this->connectionCount < $this->minSize
            && $this->pool->isEmpty()) {
            return $this->createConnection();
        }

        $connector = $this->pool->pop($this->waitTime);

        if ($connector === false) {
            if ($this->connectionCount < $this->maxSize) {
                return $this->createConnection();
            }
            throw new \Exception(sprintf('connection pop timeout, waitTime:%d, all connections: %d',
                $this->waitTime, $this->connectionCount));
        }

        if (time() - $connector['active_time'] >= $this->idleTime
            && !$this->pool->isEmpty()) {
            $this->removeConnection($connector['instance']);
            return $this->getConnection();
        }

        return $connector['instance'];
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

        if ($this->connectionCount > $this->minSize) {
            if ($this->releaseLock->push(1, self::CHANNEL_TIMEOUT) === false) {
                return $this->releaseConnection($connection);
            }

            if (!$this->pool->isEmpty()) {
                $this->releaseLock->pop(self::CHANNEL_TIMEOUT);
                $this->removeConnection($connection);
                return false;
            }
            $this->releaseLock->pop(self::CHANNEL_TIMEOUT);
        }

        $connector = [
            'active_time' => time(),
            'instance' => $connection
        ];
        if ($this->pool->push($connector, self::CHANNEL_TIMEOUT) === false) {
            $this->removeConnection($connection);
            return false;
        }
        return true;
    }

    private function removeConnection($connection)
    {
        $this->connectionCount--;
        go(function () use ($connection) {
            try {
                $this->connector->disconnect($connection);
            } catch (\Throwable $e) {
            }
        });
    }

    public function close()
    {
        go(function () {
            while (true) { //TODO use pop
                if ($this->pool->isEmpty()) {
                    break;
                }
                $connection = $this->pool->pop(static::CHANNEL_TIMEOUT);
                if ($connection !== false) {
                    $this->removeConnection($connection);
                }
            }
            $this->pool->close();
        });
        return true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
