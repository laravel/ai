<?php

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Bedrock\BedrockException;

function mockBedrockException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
{
    return new class($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException
    {
        public function __construct(
            private string $awsErrorCode,
            private int $httpStatus,
            string $message,
        ) {
            Exception::__construct($message, $httpStatus);
        }

        public function getAwsErrorCode(): ?string
        {
            return $this->awsErrorCode;
        }

        public function getStatusCode(): ?int
        {
            return $this->httpStatus;
        }
    };
}

test('throttling maps to rate limited exception', function () {
    $bedrock = mockBedrockException('ThrottlingException', 429);

    $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(RateLimitedException::class)
        ->and($aiException->getCode())->toBe(429)
        ->and($aiException->getPrevious())->toBe($bedrock);
});

test('service unavailable maps to provider overloaded', function () {
    $bedrock = mockBedrockException('ServiceUnavailableException', 503);

    $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(ProviderOverloadedException::class)
        ->and($aiException->getCode())->toBe(503);
});

test('model not ready maps to provider overloaded', function () {
    $bedrock = mockBedrockException('ModelNotReadyException', 503);

    $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(ProviderOverloadedException::class);
});

test('service quota exceeded maps to insufficient credits', function () {
    $bedrock = mockBedrockException('ServiceQuotaExceededException', 402);

    $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(InsufficientCreditsException::class)
        ->and($aiException->getCode())->toBe(402);
});

test('unknown bedrock error falls through to generic ai exception', function () {
    $bedrock = mockBedrockException('SomethingElseException', 400, 'weird error');

    $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(AiException::class)
        ->and($aiException)->not->toBeInstanceOf(RateLimitedException::class)
        ->and($aiException->getMessage())->toContain('bedrock')
        ->and($aiException->getMessage())->toContain('weird error');
});

test('non-bedrock exception with credit balance message maps to insufficient credits', function () {
    $generic = new RuntimeException('Your credit balance is too low');

    $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(InsufficientCreditsException::class);
});

test('non-bedrock exception with quota exceeded message maps to insufficient credits', function () {
    $generic = new RuntimeException('You have exceeded your current quota');

    $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(InsufficientCreditsException::class);
});

test('non-bedrock generic exception wraps as ai exception', function () {
    $generic = new RuntimeException('Some network failure', 500);

    $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

    expect($aiException)->toBeInstanceOf(AiException::class)
        ->and($aiException)->not->toBeInstanceOf(InsufficientCreditsException::class)
        ->and($aiException->getMessage())->toBe('Some network failure')
        ->and($aiException->getPrevious())->toBe($generic);
});
