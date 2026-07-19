<?php

namespace Laravel\Ai\PendingResponses\Concerns;

use Closure;
use Laravel\Ai\Providers\Provider;
use Laravel\SerializableClosure\SerializableClosure;

trait ResolvesHeaders
{
    /** @var array<string, string>|SerializableClosure */
    protected array|SerializableClosure $headers = [];

    /**
     * Specify the HTTP headers that should be sent with the request.
     *
     * @param  array<string, string>|Closure(Provider): ?array<string, string>  $headers
     */
    public function withHeaders(array|Closure $headers): self
    {
        $this->headers = $headers instanceof Closure
            ? new SerializableClosure($headers)
            : $headers;

        return $this;
    }

    /**
     * Resolve the HTTP headers for the given provider.
     *
     * @return array<string, string>
     */
    protected function resolveHeaders(Provider $provider): array
    {
        if ($this->headers instanceof SerializableClosure) {
            return ($this->headers)($provider) ?: [];
        }

        return $this->headers;
    }
}
