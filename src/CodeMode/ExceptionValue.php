<?php

namespace Laravel\Ai\CodeMode;

/**
 * A program-visible error value: plain { name, message } data, as a program catch block sees it.
 */
class ExceptionValue
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
    ) {}
}
