<?php

namespace Swover\Pool;

class PoolFactory
{
    private $pool = null;

    private $config = [];

    public function __construct(array $config, ConnectorInterface $handler)
    {
        $poolConfig = isset($config['pool_config']) ? $config['pool_config'] : [];

        $poolType = $poolConfig['pool_type'] ?? 'normal';
        // $this->connection = new ConnectionPool($poolConfig, new MedooHandler(), $config);
        $this->pool = new NormalConnectionPool($poolConfig, $handler);
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->pool, $name)) {
            return call_user_func_array([$this->pool, $name], $arguments);
        }

        try {
            $connection = $this->pool->getConnection();
            return call_user_func_array([$connection, $name], $arguments);
        } catch (\Throwable $e) {
            if ($connection ?? false) {
                $this->pool->removeConnection($connection);
                $connection = null;
            }
            try {
                $connection = $this->retrtException($e);
                return call_user_func_array([$connection, $name], $arguments);
            } catch (\Throwable $e) {
                if ($connection ?? false) {
                    $this->pool->removeConnection($connection);
                    $connection = null;
                }
                throw $e;
            }
        } finally {
            if ($connection ?? false) {
                $this->pool->releaseConnection($connection);
            }
        }
    }

    private function retrtException(\Throwable $error)
    {
        if (!isset($this->config['retry_exception']) || !is_array($this->config['retry_exception']))
            throw $error;

        foreach ($this->config['retry_exception'] as $exception) {
            if (strpos($error->getMessage(), $exception) === false)
                continue;

            return $this->pool->getConnection();
        }

        throw $error;
    }
}
