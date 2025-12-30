<?php

namespace Laravel\Ai\Gateway\Prism;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

class PrismException
{
    /**
     * Create a new AI exception from a Prism exception.
     */
    public static function toAiException(\Prism\Prism\Exceptions\PrismException $e, Provider $provider, string $model): AiException
    {
        if ($e instanceof PrismRateLimitedException) {
            throw RateLimitedException::forProvider(
                $provider->name(), $e->getCode(), $e->getPrevious()
            );
        }

        if ($e instanceof PrismProviderOverloadedException) {
            throw new ProviderOverloadedException(
                'AI provider ['.$provider->name().'] is overloaded.',
                code: $e->getCode(),
                previous: $e->getPrevious());
        }

        if (str_starts_with($e->getMessage(), 'Calling ') &&
            str_ends_with($e->getMessage(), 'tool failed')) {
            throw $e->getPrevious();
        }

        return new AiException(
            $e->getMessage(),
            code: $e->getCode(),
            previous: $e->getPrevious(),
        );
    }
}
