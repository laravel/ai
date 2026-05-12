<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class ProviderOverloadedException extends AiException implements FailoverableException
{
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'AI provider ['.$provider.'] is overloaded.',
            $code,
            $previous,
        );
    }
}
