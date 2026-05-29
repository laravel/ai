<?php

namespace Laravel\Ai\Gateway\Oracle;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class OracleException
{
    /**
     * Patterns that indicate an insufficient credits or quota error.
     *
     * @var list<string>
     */
    protected static array $insufficientCreditPatterns = [
        'quota',
        'limit exceeded',
        'insufficient',
        'billing',
    ];

    /**
     * Create a new AI exception from an OCI Generative AI request exception.
     */
    public static function toAiException(Throwable $e, string $provider, string $model): AiException
    {
        if ($e instanceof AiException) {
            return $e;
        }

        if ($e instanceof RequestException && $e->response !== null) {
            $status = $e->response->status();

            return match (true) {
                $status === 429 => RateLimitedException::forProvider($provider, $status, $e),
                $status === 402 => InsufficientCreditsException::forProvider($provider, $status, $e),
                in_array($status, [500, 502, 503, 504], true) => new ProviderOverloadedException(
                    'AI provider ['.$provider.'] is overloaded or unavailable.',
                    code: $status,
                    previous: $e,
                ),
                default => new AiException(
                    'OCI Generative AI error for provider ['.$provider.']: '.$e->getMessage(),
                    code: $status,
                    previous: $e,
                ),
            };
        }

        if (static::isInsufficientCreditsError($e)) {
            return InsufficientCreditsException::forProvider($provider, $e->getCode(), $e);
        }

        return new AiException(
            $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
        );
    }

    /**
     * Determine if the given exception indicates an insufficient credits or quota error.
     */
    protected static function isInsufficientCreditsError(Throwable $e): bool
    {
        return Str::contains(strtolower($e->getMessage()), static::$insufficientCreditPatterns);
    }
}
