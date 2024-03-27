<?php

namespace Rulatir\Cdatify\QueryPath;

use QueryPath\DOMQuery;
use QueryPath\Extension;
use QueryPath\Query;

class MapTo implements Extension
{
    public function __construct(protected Query $qp)
    {}

    public function mapToGen(callable $callback) : \Generator
    {
        foreach($this->qp->get() as $n => $v) yield $callback($n,$v);
    }

    public function mapQToGen(callable $callback) : \Generator
    {
        foreach($this->qp->get() as $n => $v) yield $callback($n,QQ($v));
    }

    public function mapToArr(callable $callback) : array
    {
        $items = $this->qp->get();
        return array_map($callback, array_keys($items), array_values($items));
    }

    public function mapQToArr(callable $callback) : array
    {
        $items = $this->qp->get();
        return array_map($callback, array_keys($items), array_map('QQ',array_values($items)));
    }

    public function eachQ(callable $callback) : DOMQuery
    {
        return $this->qp->each(function($n,$e) use ($callback) { return $callback($n,QQ($e)); });
    }
}