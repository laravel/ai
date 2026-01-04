<?php

namespace Laravel\Ai\Gateway\Prism\Concerns;

use Laravel\Ai\Contracts\Prompt;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Facades\Prism;

trait CreatesPrismTextRequests
{
    /**
     * Create a Prism text request for the given provider, model, and prompt.
     */
    protected function createPrismTextRequest(Provider $provider, string $model, ?array $schema, ?TextGenerationOptions $options = null)
    {
        $request = tap(
            ! empty($schema) ? Prism::structured() : Prism::text(),
            fn ($prism) => $this->configure($prism, $provider, $model)
        );

        if (! empty($schema)) {
            $request = $request->withSchema(new ObjectSchema($schema));
        }

        if (! is_null($schema) &&
            ! empty($schema) &&
            $provider instanceof OpenAiProvider) {
            $request = $request->withProviderOptions([
                'schema' => [
                    'strict' => true,
                ],
            ]);
        }

        if ($provider instanceof AnthropicProvider) {
            $request = $request->withProviderOptions(array_filter([
                'use_tool_calling' => $schema ? true : null,
            ]))->withMaxTokens($options?->maxTokens ?? 64_000);
        } elseif (! is_null($options?->maxTokens)) {
            $request = $request->withMaxTokens($options->maxTokens);
        }

        if (! is_null($options?->temperature)) {
            $request = $request->usingTemperature($options->temperature);
        }

        return $request->withClientOptions([
            'timeout' => 60,
        ]);
    }

    /**
     * Configure the given pending Prism request for the provider.
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using(
            static::toPrismProvider($provider),
            $model,
            ['api_key' => $provider->providerCredentials()['key']],
        );
    }
}
