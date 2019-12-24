<?php

namespace Swover\Pool;

interface ConnectorInterface
{
    public function __construct(array $config);

    public function connect();

    public function disconnect($connection);

    public function reset($connection);

    public function ping($connection);
}