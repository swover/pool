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
     * @var \SplQueue
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

        $this->pool = new \SplQueue();
    }

    public function getConnection()
    {
        if ($this->connectionCount < $this->minSize
            && $this->pool->isEmpty()) {
            return $this->createConnection();
        }

        $connection = $this->popConnection($this->waitTime);
        if ($connection === false) {
            if ($this->connectionCount < $this->maxSize) {
                return $this->createConnection();
            }
            throw new \Exception('Can not get connection!');
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
        if ($this->connectionCount >= $this->maxSize) { // isFull
            $this->removeConnection($connection);
            return false;
        }

        if ($this->connectionCount > $this->minSize) {
            if (!$this->pool->isEmpty()) {
                $this->removeConnection($connection);
                return false;
            }
        }

        $connector = [
            'active_time' => time(),
            'instance' => $connection
        ];
        //TODO 阻塞时，其他人也会判定为空 或 小于size  可以先加，然后再减少
        if ($this->pushConnection($connector, 0.001) === false) {
            $this->removeConnection($connection);
            return false;
        }
        return true;
    }

    public function removeConnection($connection)
    {
        $this->connectionCount--;
        $this->connector->disconnect($connection);
    }

    private function popConnection($waitTime)
    {
        $waitTime = $waitTime * 1000000;
        do {
            try {
                return $this->pool->shift();
            } catch (\Throwable $e) {
                usleep(1000);
                $waitTime -= 1000;
            }
        } while ($waitTime > 0);
        return false;
    }

    /**
     * @param $connection
     * @param int $waitTime wait seconds
     * @return bool
     */
    private function pushConnection($connection, $waitTime = 0)
    {
        $waitTime = $waitTime * 1000000;
        do {
            try {
                $this->pool->push($connection);
                return true;
            } catch (\Throwable $e) {
                usleep(1000);
                $waitTime -= 1000;
            }
        } while ($waitTime > 0);
        return false;
    }

    public function closeConnectionPool()
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
        $this->closeConnectionPool();
    }
}
