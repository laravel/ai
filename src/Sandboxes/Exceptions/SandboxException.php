<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

use RuntimeException;
use Throwable;

class SandboxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?string $sandboxId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
