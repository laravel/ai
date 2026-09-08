<?php

namespace Laravel\Ai\Harness;

use RuntimeException;

class HarnessException extends RuntimeException
{
    public function __construct(string $message, public ?string $sessionId = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
