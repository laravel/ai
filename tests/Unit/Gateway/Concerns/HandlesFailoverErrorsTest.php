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

test('non-string message is rethrown as the original RequestException', function (): void {
    expect(fn () => $this->gateway->withErrorHandling(
        'anthropic',
        fn () => throw failoverableException(400, [
            'error' => ['message' => ['code' => 'invalid_prompt']],
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
