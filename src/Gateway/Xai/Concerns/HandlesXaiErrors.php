<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;

trait HandlesXaiErrors
{
    use HandlesFailoverErrors;

    /**
     * Determine if xAI rejected the request for a missing or invalid API key.
     */
    protected function isInvalidApiKey(RequestException $exception): bool
    {
        $error = $exception->response?->json('error');

        return match ($exception->response?->status()) {
            401 => true,
            400 => is_string($error) && str_starts_with($error, 'Incorrect API key'),
            default => false,
        };
    }
}
