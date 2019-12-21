<?php

namespace Swover\Pool\Handler;

interface PoolType
{
    public function __construct($size);

    public function pop($waitTime);

    public function push($object, $waitTime = 0);

    public function isEmpty();

    public function isFull();

    public function count();

    public function close();
}