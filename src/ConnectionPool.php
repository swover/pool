<?php

namespace Swover\Pool;


use Swoole\Coroutine\Channel;

class ConnectionPool
{
    private $releaseLock = false;

    const LAST_ACTIVE_TIME_KEY = '__last_active_time';

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
    private $maxWaitTime = 10;

    /**
     * @var int
     */
    private $maxIdleTime = 120;

    /**
     * @var ConnectorInterface
     */
    private $connector;

    /**
     * The config for connector
     *
     * @var array
     */
    private $connectionConfig;

    /**
     * Number of connections
     *
     * @var int
     */
    private $connectionCount = 0;

    /**
     * This connector pool
     *
     * @var Channel
     */
    private $channel;

    /**
     * ConnectionPool constructor.
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     * @param array $connectionConfig
     */
    public function __construct(array $poolConfig, ConnectorInterface $connector, array $connectionConfig)
    {
        $this->minSize = $poolConfig['minSize'] ?? 3;
        $this->maxSize = $poolConfig['maxSize'] ?? 50;
        $this->maxWaitTime = $poolConfig['maxWaitTime'] ?? 5;
        $this->maxIdleTime = $poolConfig['maxIdleTime'] ?? 120;

        $this->connectionConfig = $connectionConfig;
        $this->connector = $connector;

        $this->channel = new Channel($this->maxSize + 1);
    }

    public function getConnection()
    {
        if ($this->connectionCount < $this->minSize) {
            return $this->createConnection();
        }

        if ($this->channel->isEmpty()
            && $this->connectionCount < $this->maxSize) {
            return $this->createConnection();
        }

        $connection = $this->channel->pop($this->maxWaitTime);

        $lastActiveTime = $connection->{static::LAST_ACTIVE_TIME_KEY} ?? 0;
        if (time() - $lastActiveTime >= $this->maxIdleTime
            && !$this->channel->isEmpty()) {
            $this->removeConnection($connection);
            return $this->getConnection();
        }

        if ($connection === false) {
            throw new \Exception(sprintf('connection pop timeout, waitTime:%d, all connections: %d',
                $this->maxWaitTime, $this->connectionCount));
        }

        $connection->{static::LAST_ACTIVE_TIME_KEY} = time();

        return $connection;
    }

    public function createConnection()
    {
        $this->connectionCount++;
        $connection = $this->connector->connect($this->connectionConfig);
        $connection->{static::LAST_ACTIVE_TIME_KEY} = time();
        return $connection;
    }

    public function releaseConnection($connection)
    {
        if ($this->channel->isFull()) {
            $this->removeConnection($connection);
            return false;
        }

        if ($this->connectionCount > $this->minSize + 1 //TODO
            && !$this->channel->isEmpty()) {
            $this->removeConnection($connection);
            return false;
        }

        $connection->{static::LAST_ACTIVE_TIME_KEY} = time();
        if ($this->channel->push($connection, self::CHANNEL_TIMEOUT) === false) {
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
                if ($this->channel->isEmpty()) {
                    break;
                }
                $connection = $this->channel->pop(static::CHANNEL_TIMEOUT);
                if ($connection !== false) {
                    $this->connector->disconnect($connection);
                }
            }
            $this->channel->close();
        });
        return true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
