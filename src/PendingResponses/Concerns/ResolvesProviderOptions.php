<?php

namespace Laravel\Ai\PendingResponses\Concerns;

use Closure;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\SerializableClosure\SerializableClosure;

trait ResolvesProviderOptions
{
    /** @var array<string, string>|SerializableClosure */
    protected array|SerializableClosure $headers = [];

    /** @var array<string, mixed>|SerializableClosure */
    protected array|SerializableClosure $providerOptions = [];

    /**
     * Specify HTTP headers for the request. Closures may only capture serializable values.
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
     * Specify provider-specific options for the request.
     *
     * @param  array<string, mixed>|Closure(Provider): ?array<string, mixed>  $options
     */
    public function withProviderOptions(array|Closure $options): self
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Resolve provider options for the given provider.
     *
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(Provider $provider): array
    {
        return $this->mergeHeadersIntoProviderOptions(
            $this->providerOptions instanceof SerializableClosure
                ? ($this->providerOptions)($provider) ?: []
                : $this->providerOptions,
            $this->headers instanceof SerializableClosure
                ? ($this->headers)($provider) ?: []
                : $this->headers,
        );
    }

    /**
     * Get the serializable provider options recorded by queued fakes.
     *
     * @return array<string, mixed>
     */
    protected function queuedProviderOptions(): array
    {
        return $this->mergeHeadersIntoProviderOptions(
            is_array($this->providerOptions) ? $this->providerOptions : [],
            is_array($this->headers) ? $this->headers : [],
        );
    }

    /**
     * Merge explicit request headers into the reserved provider option.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function mergeHeadersIntoProviderOptions(array $options, array $headers): array
    {
        if ($headers === []) {
            return $options;
        }

        $options[HasProviderOptions::HEADERS] = array_merge(
            $options[HasProviderOptions::HEADERS] ?? [], $headers,
        );

        return $options;
    }
}
