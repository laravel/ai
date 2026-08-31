<?php

namespace Laravel\Ai\PendingResponses\Concerns;

use Closure;
use Illuminate\Support\Arr;
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
     * Resolve the request body options and HTTP headers for the given provider.
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(Provider $provider): array
    {
        $options = $this->resolveFor($this->providerOptions, $provider);

        return [
            Arr::except($options, HasProviderOptions::HEADERS),
            array_merge(
                $options[HasProviderOptions::HEADERS] ?? [],
                $this->resolveFor($this->headers, $provider),
            ),
        ];
    }

    /**
     * Get the serializable provider options recorded by queued fakes.
     *
     * @return array<string, mixed>
     */
    protected function queuedProviderOptions(): array
    {
        $options = is_array($this->providerOptions) ? $this->providerOptions : [];

        $headers = is_array($this->headers) ? $this->headers : [];

        if ($headers === []) {
            return $options;
        }

        $options[HasProviderOptions::HEADERS] = array_merge(
            $options[HasProviderOptions::HEADERS] ?? [], $headers,
        );

        return $options;
    }

    /**
     * Resolve the given value against the provider, invoking it when it is a closure.
     *
     * @param  array<string, mixed>|SerializableClosure  $value
     * @return array<string, mixed>
     */
    private function resolveFor(array|SerializableClosure $value, Provider $provider): array
    {
        return $value instanceof SerializableClosure ? ($value)($provider) ?: [] : $value;
    }
}
