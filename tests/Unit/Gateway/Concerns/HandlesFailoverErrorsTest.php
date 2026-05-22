<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
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
    ))->toThrow(RequestException::class);
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

test('non-matching message is rethrown as the original RequestException', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(400, [
            'error' => ['message' => 'invalid prompt'],
        ]),
    ))->toThrow(RequestException::class);
});

test('402 takes precedence over an overloaded status code', function () {
    $gateway = new class
    {
        use HandlesFailoverErrors {
            withErrorHandling as public;
        }

        protected function overloadedStatusCodes(): array
        {
            return [402, 503];
        }
    };

    expect(fn () => $gateway->withErrorHandling(
        'custom',
        fn () => throw failoverableException(402, [
            'error' => ['message' => 'Insufficient Balance'],
        ]),
    ))->toThrow(InsufficientCreditsException::class);
});

test('402 takes precedence over a matching insufficient credit pattern', function () {
    expect(function () {
        try {
            $this->gateway->withErrorHandling(
                'anthropic',
                fn () => throw failoverableException(402, [
                    'error' => ['message' => 'Your credit balance is too low.'],
                ]),
            );
        } catch (InsufficientCreditsException $e) {
            expect($e->getCode())->toBe(402);

            throw $e;
        }
    })->toThrow(InsufficientCreditsException::class);
});

test('overloaded status takes precedence over a matching insufficient credit pattern', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(529, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(ProviderOverloadedException::class);
});
