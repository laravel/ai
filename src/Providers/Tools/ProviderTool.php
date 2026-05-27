<?php

namespace Laravel\Ai\Providers\Tools;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

abstract class ProviderTool implements HasProviderOptions
{
    /**
     * Provider-specific options keyed by lab name (e.g. 'openai', 'anthropic').
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $providerOptions = [];

    /**
     * Attach provider-specific options that the matching gateway will merge into the tool payload.
     */
    public function withProviderOptions(Lab|string $provider, array $options): static
    {
        $this->providerOptions[$this->normalizeProvider($provider)] = $options;

        return $this;
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions[$this->normalizeProvider($provider)] ?? [];
    }

    protected function normalizeProvider(Lab|string $provider): string
    {
        return $provider instanceof Lab ? $provider->value : $provider;
    }
}
