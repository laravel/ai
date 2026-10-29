<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
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

test('a request that is still rate limited after two retries fails over', function (): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use (&$attempts): never {
        $attempts++;

        throw failoverableException(429, headers: ['Retry-After' => '1']);
    };

    expect(fn () => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(RateLimitedException::class);

    expect($attempts)->toBe(3);

    Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(1)->second()]);
});

test('a request is not retried without a short Retry-After or on a timed out or unreachable connection', function (Exception $exception): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use ($exception, &$attempts): never {
        $attempts++;

        throw $exception;
    };

    expect(fn () => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(Exception::class);

    expect($attempts)->toBe(1);

    Sleep::assertNeverSlept();
})->with([
    'no header' => failoverableException(429),
    'long delay' => failoverableException(429, headers: ['Retry-After' => '60']),
    'http date' => failoverableException(429, headers: ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']),
    'fractional delay' => failoverableException(429, headers: ['Retry-After' => '1.5']),
    'client error' => failoverableException(400, headers: ['Retry-After' => '1']),
    'unauthorized' => failoverableException(401),
    'insufficient credits' => failoverableException(402),
    'overloaded with a long delay' => failoverableException(529, headers: ['Retry-After' => '60']),
    'timeout' => new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
    'unresolved host' => new ConnectionException('cURL error 6: Could not resolve host: api.anthropic.com'),
    'certificate' => new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'),
    'stream failure' => new ConnectionException('fopen(https://api.anthropic.com/v1/messages): Failed to open stream: HTTP request failed!'),
]);

test('an overloaded status or a refused, reset or dropped connection is retried with a backoff', function (Exception $exception): void {
    Sleep::fake();

    $attempts = 0;

    $result = $this->gateway->withErrorHandling('anthropic', function () use ($exception, &$attempts): string {
        if (++$attempts < 3) {
            throw $exception;
        }

        return 'ok';
    });

    expect($result)->toBe('ok')
        ->and($attempts)->toBe(3);

    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1)->second()]);
})->with([
    'overloaded' => failoverableException(529),
    'refused' => new ConnectionException('cURL error 7: Failed to connect to api.anthropic.com port 443 after 3 ms: Couldn\'t connect to server'),
    'empty reply' => new ConnectionException('cURL error 52: Empty reply from server'),
    'send failure' => new ConnectionException('cURL error 55: Send failure: Broken pipe'),
    'reset' => new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'),
    'refused while streaming' => new ConnectionException('fopen(https://api.anthropic.com/v1/messages): Failed to open stream: Connection refused'),
    // Guzzle 7 exceptions carry the cURL error number in their handler context...
    'reset in the handler context' => new ConnectionException('Connection failed', 0, new class extends RuntimeException
    {
        public function getHandlerContext(): array
        {
            return ['errno' => 56];
        }
    }),
]);

test('a request still failing after two retries throws the mapped exception', function (Exception $exception, string $expected): void {
    Sleep::fake();

    $attempts = 0;

    $request = function () use ($exception, &$attempts): never {
        $attempts++;

        throw $exception;
    };

    expect(fn () => $this->gateway->withErrorHandling('anthropic', $request))->toThrow($expected);

    expect($attempts)->toBe(3);

    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1)->second()]);
})->with([
    'overloaded' => [failoverableException(529), ProviderOverloadedException::class],
    'reset connection' => [new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'), ProviderConnectionException::class],
]);
