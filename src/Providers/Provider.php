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
}
