<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;

beforeEach(function (): void {
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

        protected function insufficientCreditCodes(): array
        {
            return ['insufficient_quota', 'credit_balance_exhausted', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded'];
        }
    };
});

function failoverableException(int $status, array $body = [], array $headers = []): RequestException
{
    $psr = new Psr7Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body));

    return new RequestException(new Response($psr));
}

test('overriding overloadedStatusCodes replaces the default 503', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(529),
    ))->toThrow(ProviderOverloadedException::class);

    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(503),
    ))->toThrow(RequestException::class);
});

test('429 takes precedence over insufficient credit pattern matching', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(429, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('429 with an OpenAI billing error throws InsufficientCreditsException', function (array $error): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(429, ['error' => $error]),
    ))->toThrow(InsufficientCreditsException::class);
})->with([
    'insufficient_quota code' => [['code' => 'insufficient_quota']],
    'insufficient_quota type' => [['type' => 'insufficient_quota']],
    'credit_balance_exhausted' => [['code' => 'credit_balance_exhausted']],
    'organization_spend_limit_exceeded' => [['code' => 'organization_spend_limit_exceeded']],
    'project_spend_limit_exceeded' => [['code' => 'project_spend_limit_exceeded']],
    'organization_usage_limit_exceeded' => [['code' => 'organization_usage_limit_exceeded']],
]);

test('429 with an OpenAI slow_down error still throws RateLimitedException', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(429, [
            'error' => ['code' => 'slow_down', 'type' => 'rate_limit_error'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('billing error codes are only mapped on a 429 response', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw failoverableException(529, [
            'error' => ['code' => 'insufficient_quota'],
        ]),
    ))->toThrow(ProviderOverloadedException::class);
});

test('billing error codes are ignored when insufficientCreditCodes is overridden empty', function (): void {
    $gateway = new class
    {
        use HandlesFailoverErrors {
            withErrorHandling as public;
        }

        protected function insufficientCreditCodes(): array
        {
            return [];
        }
    };

    expect(fn () => $gateway->withErrorHandling(
        'groq',
        fn () => throw failoverableException(429, [
            'error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('429 with a non-JSON body still throws RateLimitedException', function (): void {
    $psr = new Psr7Response(429, ['Content-Type' => 'text/html'], '<html>Too Many Requests</html>');

    expect(fn () => $this->gateway->withErrorHandling(
        'openai',
        fn () => throw new RequestException(new Response($psr)),
    ))->toThrow(RateLimitedException::class);
});

test('429 with an insufficient_quota error is not retried', function (): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use (&$attempts): never {
        $attempts++;

        throw failoverableException(429, [
            'error' => ['code' => 'insufficient_quota'],
        ], ['Retry-After' => '1']);
    };

    expect(fn () => $this->gateway->withErrorHandling('openai', $request))->toThrow(InsufficientCreditsException::class);

    expect($attempts)->toBe(1);

    Sleep::assertNeverSlept();
});

test('402 throws InsufficientCreditsException without requiring patterns', function (): void {
    $gateway = new class
    {
        use HandlesFailoverErrors {
            withErrorHandling as public;
        }
    };

    expect(fn (): mixed => $gateway->withErrorHandling(
        'deepseek',
        fn () => throw failoverableException(402, [
            'error' => ['message' => 'Insufficient Balance'],
        ]),
    ))->toThrow(InsufficientCreditsException::class);
});

test('non-matching message is rethrown as the original RequestException', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(400, [
            'error' => ['message' => 'invalid prompt'],
        ]),
    ))->toThrow(RequestException::class);
});

test('a rate limited or overloaded request is retried once after its Retry-After delay', function (int $status): void {
    Sleep::fake();

    $attempts = 0;

    $result = $this->gateway->withErrorHandling('anthropic', function () use ($status, &$attempts): string {
        if (++$attempts === 1) {
            throw failoverableException($status, headers: ['Retry-After' => '2']);
        }

        return 'ok';
    });

    expect($result)->toBe('ok')
        ->and($attempts)->toBe(2);

    Sleep::assertSequence([Sleep::for(2)->seconds()]);
})->with([429, 529]);

test('a request that is still rate limited after the retry fails over', function (): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use (&$attempts): never {
        $attempts++;

        throw failoverableException(429, headers: ['Retry-After' => '1']);
    };

    expect(fn () => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(RateLimitedException::class);

    expect($attempts)->toBe(2);

    Sleep::assertSleptTimes(1);
});

test('a request is not retried without a short Retry-After on a failoverable status', function (int $status, array $headers): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use ($status, $headers, &$attempts): never {
        $attempts++;

        throw failoverableException($status, headers: $headers);
    };

    expect(fn () => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(Exception::class);

    expect($attempts)->toBe(1);

    Sleep::assertNeverSlept();
})->with([
    'no header' => [429, []],
    'long delay' => [429, ['Retry-After' => '60']],
    'http date' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
    'fractional delay' => [429, ['Retry-After' => '1.5']],
    'client error' => [400, ['Retry-After' => '1']],
]);
