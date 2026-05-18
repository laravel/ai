<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
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
            if ($e->response !== null) {
                $status = $e->response->status();

                if ($status === 429) {
                    throw RateLimitedException::forProvider(
                        $providerName, $e->getCode(), $e
                    );
                }

                if ($status === 402) {
                    throw InsufficientCreditsException::forProvider(
                        $providerName, $e->getCode(), $e
                    );
                }

                if (in_array($status, $this->overloadedStatusCodes())) {
                    throw ProviderOverloadedException::forProvider(
                        $providerName, $e->getCode(), $e
                    );
                }

                if ($patterns = $this->insufficientCreditPatterns()) {
                    $message = strtolower($e->response->json('error.message', ''));

                    foreach ($patterns as $pattern) {
                        if (str_contains($message, $pattern)) {
                            throw InsufficientCreditsException::forProvider(
                                $providerName, $e->getCode(), $e
                            );
                        }
                    }
                }
            }

            throw $e;
        }
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
