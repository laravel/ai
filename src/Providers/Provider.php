<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\Provider as ProviderContract;
use Laravel\Ai\Enums\Lab;

abstract class Provider implements \Stringable, ProviderContract
{
    public function __construct(
        protected Gateway $gateway,
        protected array $config,
        protected Dispatcher $events) {}

    /**
     * Get the name of the underlying AI provider.
     */
    public function name(): string
    {
        return $this->config['name'];
    }

    /**
     * Get the name of the underlying AI driver.
     */
    public function driver(): string
    {
        return $this->config['driver'];
    }

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'],
        ];
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return array_diff_key($this->config, array_flip(['driver', 'key', 'name']));
    }

    /**
     * Get a provider instance that sends the given HTTP headers with each request.
     *
     * @param  array<string, string>  $headers
     *
     * @internal
     */
    public function withHeaders(array $headers): static
    {
        if (! filled($headers)) {
            return $this;
        }

        return tap(clone $this, function (self $provider) use ($headers): void {
            $provider->config['headers'] = array_merge($provider->config['headers'] ?? [], $headers);
        });
    }

    /**
     * Format the given provider / model list.
     */
    public static function formatProviderAndModelList(self|Lab|array|string $providers, ?string $model = null): array
    {
        if (! is_array($providers)) {
            return [self::nameOf($providers) => $model];
        }

        return (new Collection($providers))->mapWithKeys(fn ($value, $key): array => is_numeric($key)
            ? [self::nameOf($value) => null]
            : [$key => $value])->all();
    }

    /**
     * Get the name the given provider is resolved by.
     */
    private static function nameOf(self|Lab|string $provider): string
    {
        return match (true) {
            $provider instanceof self => $provider->name(),
            $provider instanceof Lab => $provider->value,
            default => $provider,
        };
    }

    /**
     * Convert the provider to its string representation.
     */
    public function __toString(): string
    {
        // In 2.x, cast every provider to its name and drop the "dynamic" flag.
        return ($this->config['dynamic'] ?? false) ? $this->name() : $this->driver();
    }
}
