<?php

namespace Swover\Pool;


use Swoole\Coroutine\Channel;

class ConnectionPool
{
    private $releaseLock = false;

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
    private $bufferSize = 0;

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
        $this->bufferSize = $poolConfig['bufferSize'] ?? 0;
        $this->waitTime = $poolConfig['waitTime'] ?? 5;
        $this->idleTime = $poolConfig['idleTime'] ?? 120;

        $this->connectionConfig = $connectionConfig;
        $this->connector = $connector;

        $this->channel = new Channel($this->maxSize);
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

        $connector = $this->channel->pop($this->waitTime);

        if (time() - $connector['active_time'] >= $this->idleTime
            && !$this->channel->isEmpty()) {
            $this->removeConnection($connector['instance']);
            return $this->getConnection();
        }

        if ($connector === false) {
            throw new \Exception(sprintf('connection pop timeout, waitTime:%d, all connections: %d',
                $this->waitTime, $this->connectionCount));
        }

        return $connector['instance'];
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

        if ($this->connectionCount > $this->minSize + ($this->bufferSize ? : ceil(($this->maxSize - $this->minSize) / 2))
            && !$this->channel->isEmpty()) {
            $this->removeConnection($connection);
            return false;
        }

        $connector = [
            'active_time' => time(),
            'instance' => $connection
        ];
        if ($this->channel->push($connector, self::CHANNEL_TIMEOUT) === false) {
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
