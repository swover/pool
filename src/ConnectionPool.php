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
     * @var Channel
     */
    private $channel;

    /**
     * @var Channel
     */
    private $releaseLock;

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
        $this->releaseLock = new Channel(1);
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

        if ($this->connectionCount > $this->minSize) {
            if ($this->releaseLock->push(1, self::CHANNEL_TIMEOUT) === false) {
                return $this->releaseConnection($connection);
            }

            $this->releaseLock->pop(self::CHANNEL_TIMEOUT);
            if (!$this->channel->isEmpty()) {
                $this->removeConnection($connection);
                return false;
            }
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
