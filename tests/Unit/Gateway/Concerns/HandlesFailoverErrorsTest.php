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
    };

    $this->customGateway = new class
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

test('returns the callback result when no exception is thrown', function () {
    $result = $this->gateway->withErrorHandling('test', fn () => 'ok');

    expect($result)->toBe('ok');
});

test('rethrows non-RequestException exceptions unchanged', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'test',
        fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class, 'boom');
});

test('429 response throws a RateLimitedException for the named provider', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(429),
    ))->toThrow(
        RateLimitedException::class,
        'Application rate limited by AI provider [openai].',
    );
});

test('default 503 response throws a ProviderOverloadedException', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(503),
    ))->toThrow(ProviderOverloadedException::class);
});

test('custom overloadedStatusCodes is honored over the default 503', function () {
    expect(fn () => $this->customGateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(529),
    ))->toThrow(ProviderOverloadedException::class);

    expect(fn () => $this->customGateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(503),
    ))->toThrow(RequestException::class);
});

test('matching insufficientCreditPattern throws an InsufficientCreditsException', function () {
    expect(fn () => $this->customGateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(400, [
            'error' => ['message' => 'Your credit balance is too low to access the API.'],
        ]),
    ))->toThrow(InsufficientCreditsException::class);
});

test('429 takes precedence over insufficient credit pattern matching', function () {
    expect(fn () => $this->customGateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(429, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('non-matching message is rethrown as the original RequestException', function () {
    expect(fn () => $this->customGateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(400, [
            'error' => ['message' => 'invalid prompt'],
        ]),
    ))->toThrow(RequestException::class);
});

test('default insufficientCreditPatterns is empty so no credit detection occurs', function () {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(400, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(RequestException::class);
});
