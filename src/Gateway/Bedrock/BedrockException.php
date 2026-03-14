<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

class BedrockException
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
        'service quota',
    ];

    /**
     * Create a new AI exception from an AWS Bedrock exception.
     *
     * @throws InsufficientCreditsException
     * @throws ProviderOverloadedException
     * @throws RateLimitedException
     */
    public static function toAiException(\Throwable $e, string $provider, string $model): AiException
    {
        // Handle AWS Bedrock-specific exceptions
        if ($e instanceof BedrockRuntimeException) {
            $errorCode = $e->getAwsErrorCode();

            return match ($errorCode) {
                'ThrottlingException' => RateLimitedException::forProvider($provider, $e->getStatusCode(), $e),
                'ServiceUnavailableException', 'ModelNotReadyException' => new ProviderOverloadedException(
                    'AI provider ['.$provider.'] is overloaded or unavailable.',
                    code: $e->getStatusCode(),
                    previous: $e
                ),
                'ServiceQuotaExceededException' => InsufficientCreditsException::forProvider($provider, $e->getStatusCode(), $e),
                default => self::handleGenericException($e, $provider),
            };
        }

        // Check message patterns for quota/billing issues
        if (static::isInsufficientCreditsError($e)) {
            throw InsufficientCreditsException::forProvider($provider, $e->getCode(), $e);
        }

        return new AiException(
            $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
        );
    }

    /**
     * Handle generic AWS exceptions.
     */
    protected static function handleGenericException(\Throwable $e, string $provider): AiException
    {
        return new AiException(
            'AWS Bedrock error for provider ['.$provider.']: '.$e->getMessage(),
            code: $e->getCode(),
            previous: $e,
        );
    }

    /**
     * Determine if the given exception indicates an insufficient credits or quota error.
     */
    protected static function isInsufficientCreditsError(\Throwable $e): bool
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
