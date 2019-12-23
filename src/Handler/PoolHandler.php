<?php

namespace Swover\Pool\Handler;

abstract class PoolHandler
{
    protected $handler = null;

    protected $size = 0;

    public function __construct($size)
    {
        $this->size = $size;
    }

    abstract public function pop($waitTime);

    abstract public function push($object, $waitTime = 0);

    abstract public function isEmpty();

    abstract public function isFull();

    abstract public function count();

    abstract public function close();

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->handler, $name], $arguments);
    }
}