<?php

namespace Laravel\Ai\CodeMode;

use RuntimeException;

/**
 * Carries a program-thrown value so try/catch inside the program can intercept it.
 */
class ProgramThrow extends RuntimeException
{
    public function __construct(public readonly ExceptionValue $value)
    {
        parent::__construct($value->message);
    }
}
