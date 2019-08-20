<?php

namespace Swover\Pool;


use Swoole\Coroutine\Channel;

class ConnectionPool
{
    private $releaseLock = false;

    const CHANNEL_TIMEOUT = 0.001;

    private $minActive = 3;
    private $maxActive = 50;
    private $maxWaitTime = 10;

    private $connector;
    private $connectionConfig;
    private $connectionCount = 0;
    private $channel;

    public function __construct(array $poolConfig, ConnectorInterface $connector, array $connectionConfig)
    {
        $this->minActive = $poolConfig['minActive'] ?? 3;
        $this->maxActive = $poolConfig['maxActive'] ?? 50;
        $this->maxWaitTime = $poolConfig['maxWaitTime'] ?? 5;

        $this->connectionConfig = $connectionConfig;
        $this->connector = $connector;

        $this->channel = new Channel($this->maxActive + 1);
    }

    public function getConnection()
    {
        if ($this->connectionCount < $this->minActive) {
            return $this->createConnection();
        }

        if ($this->channel->isEmpty()
            && $this->connectionCount < $this->maxActive) {
            return $this->createConnection();
        }

        $connection = $this->channel->pop($this->maxWaitTime);

        if ($connection === false) {
            throw new \Exception(sprintf('connection pop timeout, waitTime:%d, all connections: %d',
                $this->maxWaitTime, $this->connectionCount));
        }

        return $connection;
    }

    public function createConnection()
    {
        $this->connectionCount++;
        $connection = $this->connector->connect($this->connectionConfig);
        return $connection;
    }

    public function releaseConnection($connection)
    {
        if ($this->channel->isFull()) {
            $this->removeConnection($connection);
            return false;
        }

        if ($this->connectionCount > $this->minActive) {
            if (!$this->channel->isEmpty()) {
                $this->removeConnection($connection);
                return false;
            }
        }

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
            while (true) {
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
