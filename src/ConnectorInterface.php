<?php

namespace Swover\Pool;

interface ConnectorInterface
{
    public function connect(array $config);

    public function disconnect($connection);

    public function reset($connection, array $config);
}