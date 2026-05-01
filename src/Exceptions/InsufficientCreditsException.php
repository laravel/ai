<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class InsufficientCreditsException extends AiException implements FailoverableException
{
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new static(
            'AI provider ['.$provider.'] has insufficient credits or quota.',
            $code,
            $previous
        );
    }
}
