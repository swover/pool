<?php

namespace Swover\Pool\Handler;

class SplQueue implements PoolType
{

    protected $handler = null;

    protected $length = 0;

    public function __construct($size)
    {
        $this->handler = new \SplQueue();
        $this->length = $size;
    }

    public function pop($waitTime)
    {
        $waitTime = $waitTime * 1000 * 1000;
        do {
            try {
                return $this->handler->shift();
            } catch (\Throwable $e) {
                usleep(1000);
                $waitTime -= 1000;
            }
        } while ($waitTime > 0);
        return false;
    }

    public function push($object, $waitTime = 0)
    {
        $waitTime = $waitTime * 1000 * 1000;
        do {
            try {
                //TODO 判断是否已满
                $this->handler->push($object);
                return true;
            } catch (\Throwable $e) {
                usleep(1000);
                $waitTime -= 1000;
            }
        } while ($waitTime > 0);
        return false;
    }

    public function count()
    {
        return $this->handler->count();
    }

    public function isFull()
    {
        return $this->handler->count() >= $this->length;
    }

    public function isEmpty()
    {
        return $this->handler->isEmpty();
    }

    public function close()
    {
        return $this->handler = null;
    }
}