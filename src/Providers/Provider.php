<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Enums\Lab;

abstract class Provider
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
     * Format the given provider / model list.
     */
    public static function formatProviderAndModelList(Lab|array|string $providers, ?string $model = null): array
    {
        if ($providers instanceof Lab) {
            return [$providers->value => $model];
        }

        if (is_string($providers)) {
            return [$providers => $model];
        }

        return (new Collection($providers))->mapWithKeys(function ($value, $key) {
            return is_numeric($key)
                ? [($value instanceof Lab ? $value->value : $value) => null]
                : [($key instanceof Lab ? $key->value : $key) => $value];
        })->all();
    }

    /**
     * Normalize the provider input into a list of [specifier, ?model] tuples for failover iteration.
     *
     * @return array<int, array{0: self|string, 1: ?string}>
     */
    public static function normalizeSpecifiers(Lab|array|string|self $providers, ?string $model = null): array
    {
        if (! is_array($providers)) {
            return [[static::unwrapLab($providers), $model]];
        }

        $tuples = [];

        foreach ($providers as $key => $value) {
            if ($value instanceof self) {
                $tuples[] = [$value, null];

                continue;
            }

            if (is_int($key)) {
                $tuples[] = [static::unwrapLab($value), null];

                continue;
            }

            $tuples[] = [static::unwrapLab($key), $value];
        }

        return $tuples;
    }

    /**
     * Unwrap a Lab enum into its string value, leaving providers and strings untouched.
     */
    private static function unwrapLab(Lab|self|string $specifier): self|string
    {
        return $specifier instanceof Lab ? $specifier->value : $specifier;
    }

    /**
     * Convert the provider to its string representation.
     */
    public function __toString(): string
    {
        return $this->driver();
    }
}
