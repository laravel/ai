<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class ProviderConnectionException extends AiException implements FailoverableException
{
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Could not connect to AI provider ['.$provider.'].',
            $code,
            $previous,
        );
    }
}
