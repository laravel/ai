<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;

beforeEach(function () {
    $this->gateway = new class
    {
        use HandlesFailoverErrors {
            withErrorHandling as public;
        }

        protected function overloadedStatusCodes(): array
        {
            return [529];
        }

        protected function insufficientCreditPatterns(): array
        {
            return ['credit balance', 'quota exceeded'];
        }
    };
});

function failoverableException(int $status, array $body = []): RequestException
{
    $psr = new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body));

    return new RequestException(new Response($psr));
}

test('overriding overloadedStatusCodes replaces the default 503', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(529),
    ))->toThrow(ProviderOverloadedException::class);

    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(503),
    ))->toThrow(ProviderRequestException::class);
});

test('429 takes precedence over insufficient credit pattern matching', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(429, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('402 throws InsufficientCreditsException without requiring patterns', function () {
    $gateway = new class
    {
        use HandlesFailoverErrors {
            withErrorHandling as public;
        }
    };

    expect(fn () => $gateway->withErrorHandling(
        'deepseek',
        fn () => throw failoverableException(402, [
            'error' => ['message' => 'Insufficient Balance'],
        ]),
    ))->toThrow(InsufficientCreditsException::class);
});

test('unmapped status is wrapped in a ProviderRequestException with provider error context', function () {
    try {
        $this->gateway->withErrorHandling(
            'anthropic',
            fn () => throw failoverableException(400, [
                'error' => ['message' => "Invalid 'model' provided."],
            ]),
        );

        $this->fail('Expected ProviderRequestException to be thrown.');
    } catch (ProviderRequestException $e) {
        expect($e->provider())->toBe('anthropic')
            ->and($e->status())->toBe(400)
            ->and($e->errorBody())->toBe(['error' => ['message' => "Invalid 'model' provided."]])
            ->and($e->getMessage())->toContain("Invalid 'model' provided.")
            ->and($e->getMessage())->toContain('400')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }
});

test('the full raw body is used as the message when no structured error field exists', function () {
    try {
        $this->gateway->withErrorHandling(
            'anthropic',
            fn () => throw failoverableException(418, ['unexpected' => 'shape']),
        );

        $this->fail('Expected ProviderRequestException to be thrown.');
    } catch (ProviderRequestException $e) {
        expect($e->status())->toBe(418)
            ->and($e->getMessage())->toContain('unexpected')
            ->and($e->errorBody())->toBe(['unexpected' => 'shape']);
    }
});

test('the provider error message is extracted from varying body shapes', function (array $body, string $expected) {
    try {
        $this->gateway->withErrorHandling(
            'anthropic',
            fn () => throw failoverableException(400, $body),
        );

        $this->fail('Expected ProviderRequestException to be thrown.');
    } catch (ProviderRequestException $e) {
        expect($e->getMessage())->toContain($expected);
    }
})->with([
    'error.message' => [['error' => ['message' => 'nested message']], 'nested message'],
    'error string' => [['error' => 'plain string error'], 'plain string error'],
    'top-level message' => [['message' => 'top message'], 'top message'],
    'detail' => [['detail' => 'detail message'], 'detail message'],
]);

test('failover exceptions also carry the provider error context', function () {
    try {
        $this->gateway->withErrorHandling(
            'anthropic',
            fn () => throw failoverableException(429, ['error' => ['message' => 'slow down']]),
        );

        $this->fail('Expected RateLimitedException to be thrown.');
    } catch (RateLimitedException $e) {
        expect($e->provider())->toBe('anthropic')
            ->and($e->status())->toBe(429)
            ->and($e->errorBody())->toBe(['error' => ['message' => 'slow down']]);
    }
});
