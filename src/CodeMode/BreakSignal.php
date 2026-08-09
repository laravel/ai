<?php

namespace Laravel\Ai\CodeMode;

use Exception;

class BreakSignal extends Exception
{
    public function __construct(public int $levels = 1)
    {
        parent::__construct();
    }
}
