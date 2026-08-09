<?php

namespace Laravel\Ai\CodeMode;

use Exception;

class ReturnSignal extends Exception
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct();
    }
}
