<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class BedrockException
{
    /**
     * Patterns that indicate an insufficient credits or quota error.
     *
     * @var list<string>
     */
    protected static array $insufficientCreditPatterns = [
        'credit balance',
        'insufficient',
        'quota exceeded',
        'exceeded your current quota',
        'billing',
        'service quota',
    ];

    /**
     * Create a new AI exception from an AWS Bedrock exception.
     */
    public static function toAiException(Throwable $e, string $provider, string $model): AiException
    {
        if ($e instanceof BedrockRuntimeException) {
            return match ($e->getAwsErrorCode()) {
                'ThrottlingException' => RateLimitedException::forProvider($provider, $e->getStatusCode(), $e),
                'ServiceUnavailableException',
                'ModelNotReadyException',
                'ModelTimeoutException',
                'ModelStreamErrorException',
                'InternalServerException' => new ProviderOverloadedException(
                    'AI provider ['.$provider.'] is overloaded or unavailable.',
                    code: $e->getStatusCode(),
                    previous: $e,
                ),
                'ServiceQuotaExceededException' => InsufficientCreditsException::forProvider($provider, $e->getStatusCode(), $e),
                default => new AiException(
                    'AWS Bedrock error for provider ['.$provider.']: '.$e->getMessage(),
                    code: $e->getCode(),
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
