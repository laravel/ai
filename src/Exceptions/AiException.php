<?php

namespace Laravel\Ai\Exceptions;

use Exception;
use Throwable;

class AiException extends Exception
{
    /**
     * Create a new AI exception instance.
     *
     * @param  array<string, mixed>|null  $errorBody
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected ?string $provider = null,
        protected ?int $status = null,
        protected ?array $errorBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The provider that raised the error, if known.
     */
    public function provider(): ?string
    {
        return $this->provider;
    }

    /**
     * The HTTP status code returned by the provider, if any.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * The raw error body returned by the provider, if any.
     *
     * @return array<string, mixed>|null
     */
    public function errorBody(): ?array
    {
        return $this->errorBody;
    }

    /**
     * Attach provider error context to the exception.
     *
     * @param  array<string, mixed>|null  $errorBody
     * @return $this
     */
    public function withContext(?string $provider, ?int $status, ?array $errorBody): static
    {
        $this->provider = $provider;
        $this->status = $status;
        $this->errorBody = $errorBody;

        return $this;
    }
}
