<?php

namespace Laravel\Ai\Gateway\Prism;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

class PrismException
{
    /**
     * The patterns used to detect insufficient credits or quota errors from provider responses.
     *
     * @var list<string>
     */
    protected static array $insufficientCreditPatterns = [
        'credit balance',
        'insufficient',
        'quota exceeded',
        'exceeded your current quota',
        'billing',
    ];

    /**
     * Create a new AI exception from a Prism exception.
     *
     * @throws InsufficientCreditsException
     * @throws ProviderOverloadedException
     * @throws RateLimitedException
     * @throws \Throwable Rethrows the previous exception when the message indicates a tool call failed.
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
            str_ends_with($e->getMessage(), 'tool failed') &&
            $e->getPrevious() !== null) {
            throw $e->getPrevious();
        }

        if (static::isInsufficientCreditsError($e)) {
            throw InsufficientCreditsException::forProvider(
                $provider->name(), $e->getCode(), $e->getPrevious()
            );
        }

        return new AiException(
            $e->getMessage(),
            code: $e->getCode(),
            previous: $e->getPrevious(),
        );
    }

    /**
     * Determine if the given exception indicates an insufficient credits or quota error.
     */
    protected static function isInsufficientCreditsError(\Prism\Prism\Exceptions\PrismException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (static::$insufficientCreditPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
