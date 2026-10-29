<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

trait HandlesFailoverErrors
{
    /**
     * Execute a callback with failoverable error handling.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return retry(
                3,
                $callback,
                fn (int $attempt, Throwable $exception): int => $this->retryDelay($attempt, $exception),
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
            );
        } catch (ConnectionException $connectionException) {
            throw ProviderConnectionException::forProvider(
                $providerName, $connectionException->getCode(), $connectionException
            );
        } catch (RequestException $requestException) {
            if ($requestException->response !== null) {
                $status = $requestException->response->status();

                if ($this->isInsufficientCredits($requestException)) {
                    throw InsufficientCreditsException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                if ($status === 429) {
                    throw RateLimitedException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                if (in_array($status, $this->overloadedStatusCodes())) {
                    throw ProviderOverloadedException::forProvider(
                        $providerName, $requestException->getCode(), $requestException
                    );
                }

                $message = $requestException->response->json('error.message');

                if (is_string($message) && $patterns = $this->insufficientCreditPatterns()) {
                    $message = strtolower($message);

                    foreach ($patterns as $pattern) {
                        if (str_contains($message, (string) $pattern)) {
                            throw InsufficientCreditsException::forProvider(
                                $providerName, $requestException->getCode(), $requestException
                            );
                        }
                    }
                }
            }

            throw $requestException;
        }
    }

    /**
     * Determine if a failed request should be retried: after a short Retry-After, on an overloaded status, or on a dropped connection.
     */
    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return $this->isDroppedConnection($exception);
        }

        if (! $exception instanceof RequestException || $exception->response === null) {
            return false;
        }

        if ($exception->response->hasHeader('Retry-After')) {
            return $this->retryAfterSeconds($exception) !== null;
        }

        return in_array($exception->response->status(), $this->overloadedStatusCodes());
    }

    /**
     * Get the milliseconds to wait before the given retry: the short Retry-After, else 0.5s doubling per attempt.
     */
    protected function retryDelay(int $attempt, Throwable $exception): int
    {
        $retryAfter = $this->retryAfterSeconds($exception);

        return $retryAfter !== null ? $retryAfter * 1000 : 500 * 2 ** ($attempt - 1);
    }

    /**
     * Determine if the connection was refused, reset or dropped, rather than timed out or never resolved.
     */
    protected function isDroppedConnection(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();

        // Guzzle 7 keeps cURL's error number in the handler context, Guzzle 8 only in the message...
        $errno = $previous !== null && method_exists($previous, 'getHandlerContext')
            ? ($previous->getHandlerContext()['errno'] ?? null)
            : (preg_match('/^cURL error (\d+):/', $exception->getMessage(), $matches) ? (int) $matches[1] : null);

        if ($errno !== null) {
            return in_array($errno, [7, 52, 55, 56], true);
        }

        // Streamed requests go through PHP's stream wrapper, where a refused connection is the only failure told apart from a timeout...
        return str_contains($exception->getMessage(), 'Connection refused');
    }

    /**
     * Get the short Retry-After delay of a rate limited or overloaded response, if it has one.
     */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException || $exception->response === null) {
            return null;
        }

        if ($this->isInsufficientCredits($exception)) {
            return null;
        }

        $status = $exception->response->status();

        if ($status !== 429 && ! in_array($status, $this->overloadedStatusCodes())) {
            return null;
        }

        $retryAfter = $exception->response->header('Retry-After');

        return ctype_digit($retryAfter) && (int) $retryAfter <= 10 ? (int) $retryAfter : null;
    }

    /**
     * Determine if the response is a 402, or a 429 insufficient credits or quota error rather than a rate limit.
     */
    protected function isInsufficientCredits(RequestException $exception): bool
    {
        $status = $exception->response?->status();

        if ($status === 402) {
            return true;
        }

        if ($status !== 429) {
            return false;
        }

        $codes = $this->insufficientCreditCodes();

        return in_array($exception->response->json('error.code'), $codes, true)
            || in_array($exception->response->json('error.type'), $codes, true);
    }

    /**
     * The status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [502, 503, 504, 520, 522, 524];
    }

    /**
     * The patterns used to detect insufficient credits or quota errors.
     *
     * @return list<string>
     */
    protected function insufficientCreditPatterns(): array
    {
        return [];
    }

    /**
     * The error codes or types that indicate insufficient credits or quota on a 429 response.
     *
     * @return list<string>
     */
    protected function insufficientCreditCodes(): array
    {
        // OpenAI sends these with a 429 status, and may still use "insufficient_quota" as the error type.
        return [
            'insufficient_quota',
            'credit_balance_exhausted',
            'organization_spend_limit_exceeded',
            'project_spend_limit_exceeded',
            'organization_usage_limit_exceeded',
        ];
    }
}
