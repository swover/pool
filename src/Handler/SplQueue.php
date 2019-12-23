<?php

namespace Swover\Pool\Handler;

class SplQueue extends PoolHandler
{

    public function __construct($size)
    {
        parent::__construct($size);
        $this->handler = new \SplQueue();
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
                if ($this->isFull()) continue;

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
        return $this->handler->count() >= $this->size;
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