<?php

namespace Swover\Pool;

interface ConnectorPoolInterface
{
    public function getConnection();

    public function createConnection();

    public function releaseConnection($connection);

    public function removeConnection($connection);

    public function close();
}