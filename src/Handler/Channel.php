<?php

namespace Swover\Pool\Handler;

class Channel extends PoolHandler
{
    public function __construct($size)
    {
        parent::__construct($size);
        $this->handler = new \Swoole\Coroutine\Channel($size);
    }

    public function pop($waitTime)
    {
        return $this->handler->pop($waitTime);
    }

    public function push($object, $waitTime = 0)
    {
        return $this->handler->push($object, $waitTime);
    }

    public function isEmpty()
    {
        return $this->handler->isEmpty();
    }

    public function isFull()
    {
        return $this->handler->isFull();
    }

    public function count()
    {
        return $this->handler->length();
    }

    public function close()
    {
        $this->handler->close();
        return $this->handler = null;
    }
}