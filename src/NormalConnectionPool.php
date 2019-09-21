<?php

namespace Swover\Pool;

class NormalConnectionPool implements PoolInterface
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
     * @var \SplQueue
     */
    private $pool;

    /**
     * ConnectionPool constructor.
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     * @param array $connectionConfig
     */
    public function __construct(array $poolConfig, ConnectorInterface $connector, array $connectionConfig)
    {
        $this->minSize = $poolConfig['minSize'] ?? 1;
        $this->maxSize = $poolConfig['maxSize'] ?? 10;
        $this->idleTime = $poolConfig['idleTime'] ?? 120;

        $this->connectionConfig = $connectionConfig;
        $this->connector = $connector;

        $this->pool = new \SplQueue();
    }
    
    public function getConnection()
    {
        try {
            $connector = $this->pool->shift();
        } catch (\RuntimeException $e) {
            if ($this->connectionCount < $this->maxSize) {
                return $this->createConnection();
            }
            throw new \Exception('Can not get connection!');
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
        $connection = $this->connector->connect($this->connectionConfig);
        return $connection;
    }

    public function releaseConnection($connection)
    {
        if ($this->connectionCount >= $this->maxSize) {
            $this->removeConnection($connection);
            return false;
        }

        if ($this->connectionCount > $this->minSize
            && !$this->pool->isEmpty()) {
            $this->removeConnection($connection);
            return false;
        }

        $connector = [
            'active_time' => time(),
            'instance' => $connection
        ];
        $this->pool->push($connector);
        return true;
    }

    public function removeConnection($connection)
    {
        $this->connectionCount--;
        $this->connector->disconnect($connection);
    }

    public function close()
    {
        while (true) {
            if ($this->pool->isEmpty()
                && $this->connectionCount <= 0) {
                break;
            }
            try {
                $connection = $this->pool->shift();
                $this->removeConnection($connection);
            } catch (\RuntimeException $e) {
            }
        }
        $this->pool = null;
        return true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
