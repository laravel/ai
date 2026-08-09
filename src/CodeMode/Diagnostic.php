<?php

namespace Laravel\Ai\CodeMode;

use RuntimeException;

/**
 * A typed execution failure returned to the model as data rather than thrown to the host.
 */
class Diagnostic extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly bool $catchable = false,
    ) {
        parent::__construct($message);
    }
}
