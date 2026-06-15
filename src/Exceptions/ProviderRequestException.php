<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

class ProviderRequestException extends AiException
{
    /**
     * Create an exception for a failed provider HTTP response.
     *
     * @param  array<string, mixed>|null  $errorBody
     */
    public static function forResponse(
        string $provider,
        int $status,
        ?array $errorBody = null,
        ?string $message = null,
        int $code = 0,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'AI provider [%s] request failed with status [%d]: %s',
                $provider,
                $status,
                $message ?? 'Unknown error.',
            ),
            $code,
            $previous,
            $provider,
            $status,
            $errorBody,
        );
    }
}
