<?php

namespace Swover\Pool;

interface ConnectorInterface
{
    public function connect();

    public function disconnect($connection);

    public function reset($connection);
}