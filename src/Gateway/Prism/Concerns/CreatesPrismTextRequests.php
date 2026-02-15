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
    protected function createPrismTextRequest(
        Provider $provider,
        string $model,
        ?array $schema,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ) {
        $request = tap(
            ! empty($schema) ? Prism::structured() : Prism::text(),
            fn ($prism) => $this->configure($prism, $provider, $model)
        );

        if (! empty($schema)) {
            $request = $this->withStructuredOutputOptions($request, $provider, $schema);
        }

        $request = $this->withProviderOptions($request, $provider, $schema, $options);

        if (! is_null($options?->temperature)) {
            $request = $request->usingTemperature($options->temperature);
        }

        return $request->withClientOptions(['timeout' => $timeout ?? 60]);
    }

    /**
     * Add structured output options to the request.
     */
    protected function withStructuredOutputOptions($request, Provider $provider, array $schema)
    {
        $request = $request->withSchema(new ObjectSchema($schema));

        if ($provider instanceof OpenAiProvider) {
            $request = $request->withProviderOptions(['schema' => ['strict' => true]]);
        }

        return $request;
    }

    /**
     * Add provider-specific options to the request.
     */
    protected function withProviderOptions($request, Provider $provider, ?array $schema, ?TextGenerationOptions $options)
    {
        if ($provider instanceof AnthropicProvider) {
            return $request
                ->withProviderOptions(array_filter([
                    'use_tool_calling' => $schema ? true : null,
                ]))
                ->withMaxTokens($options?->maxTokens ?? 64_000);
        }

        if (! is_null($options?->maxTokens)) {
            $request = $request->withMaxTokens($options->maxTokens);
        }

        if (! is_null($options?->reasoningEffort)) {
            $request = $request->withProviderOptions([
                'reasoning' => ['effort' => $options->reasoningEffort],
            ]);
        }

        return $request;
    }

    /**
     * Configure the given pending Prism request for the provider.
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using(
            static::toPrismProvider($provider),
            $model,
            array_filter([
                ...$provider->additionalConfiguration(),
                ...($provider->driver() === 'anthropic')
                   ? ['anthropic_beta' => 'web-fetch-2025-09-10']
                   : [],
                'api_key' => $provider->providerCredentials()['key'],
            ]),
        );
    }
}
