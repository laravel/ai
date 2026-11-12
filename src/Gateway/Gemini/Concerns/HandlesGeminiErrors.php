<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;

trait HandlesGeminiErrors
{
    use HandlesFailoverErrors;

    /**
     * Determine if Gemini rejected the request for a missing or invalid API key.
     */
    protected function isInvalidApiKey(RequestException $exception): bool
    {
        // The Interactions API wraps its error body in a list.
        $error = $exception->response?->json('0.error') ?? $exception->response?->json('error');

        $message = is_string($error['message'] ?? null) ? $error['message'] : '';

        return match ($exception->response?->status()) {
            401 => true,
            400 => collect($error['details'] ?? [])->contains('reason', 'API_KEY_INVALID'),
            403 => Str::contains($message, ['unregistered callers', 'reported as leaked']),
            default => false,
        };
    }
}
