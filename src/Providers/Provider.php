<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\Gateway;

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
     * Format the given provider / model list.
     */
    public static function formatProviderAndModelList(array|string $providers, ?string $model = null): array
    {
        if (is_string($providers)) {
            return [$providers => $model];
        }

        return collect($providers)->mapWithKeys(function ($value, $key) {
            return is_numeric($key)
                ? [$value => null] // Provider name and default model...
                : [$key => $value]; // Provider name and model name...
        })->all();
    }
}
