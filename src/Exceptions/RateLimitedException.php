<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class RateLimitedException extends AiException implements FailoverableException
{
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Application rate limited by AI provider ['.$provider.'].',
            $code,
            $previous
        );
    }
}
