<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class InsufficientCreditsException extends AiException implements FailoverableException
{
    public function __construct(
        public readonly string $provider,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            $provider,
            'AI provider ['.$provider.'] has insufficient credits or quota.',
            $code,
            $previous,
        );
    }
}
