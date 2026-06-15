<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

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
            return $callback();
        } catch (RequestException $e) {
            if ($e->response === null) {
                throw $e;
            }

            $status = $e->response->status();
            $body = $this->extractErrorBody($e->response);
            $message = $this->extractErrorMessage($e->response);

            if ($status === 429) {
                throw RateLimitedException::forProvider($providerName, $e->getCode(), $e)
                    ->withContext($providerName, $status, $body);
            }

            if ($status === 402) {
                throw InsufficientCreditsException::forProvider($providerName, $e->getCode(), $e)
                    ->withContext($providerName, $status, $body);
            }

            if (in_array($status, $this->overloadedStatusCodes())) {
                throw ProviderOverloadedException::forProvider($providerName, $e->getCode(), $e)
                    ->withContext($providerName, $status, $body);
            }

            if ($patterns = $this->insufficientCreditPatterns()) {
                $haystack = strtolower($message ?? '');

                foreach ($patterns as $pattern) {
                    if (str_contains($haystack, $pattern)) {
                        throw InsufficientCreditsException::forProvider($providerName, $e->getCode(), $e)
                            ->withContext($providerName, $status, $body);
                    }
                }
            }

            throw ProviderRequestException::forResponse(
                $providerName, $status, $body, $message, $e->getCode(), $e,
            );
        }
    }

    /**
     * Extract the raw error body from a provider response.
     *
     * @return array<string, mixed>|null
     */
    protected function extractErrorBody(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Extract a human-readable error message from a provider response.
     */
    protected function extractErrorMessage(Response $response): ?string
    {
        $body = $this->extractErrorBody($response);

        if ($body !== null) {
            foreach ([
                $body['error']['message'] ?? null,
                $body['error'] ?? null,
                $body['message'] ?? null,
                $body['detail'] ?? null,
                $body['error']['status'] ?? null,
            ] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        $raw = trim((string) $response->body());

        return $raw === '' ? null : Str::limit($raw, 500);
    }

    /**
     * The status codes that indicate a provider is overloaded.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [503];
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
}
