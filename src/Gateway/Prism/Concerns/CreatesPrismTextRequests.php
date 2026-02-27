<?php

namespace Laravel\Ai\Gateway\Prism\Concerns;

use Laravel\Ai\Contracts\Prompt;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\AzureOpenAiProvider;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\MistralProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\XaiProvider;
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
        $thinking = $options?->thinking;

        if ($provider instanceof AnthropicProvider) {
            return $request
                ->withProviderOptions(array_filter([
                    'use_tool_calling' => $schema ? true : null,
                    'thinking' => $thinking ? array_filter([
                        'enabled' => $thinking['enabled'],
                        'budgetTokens' => $thinking['budgetTokens'],
                    ], fn ($value) => $value !== null) : null,
                ], fn ($value) => $value !== null))
                ->withMaxTokens($options?->maxTokens ?? 64_000);
        }

        if ($thinking && $provider instanceof GeminiProvider) {
            $request = $request->withProviderOptions(array_filter([
                'thinkingBudget' => $thinking['budgetTokens'],
                'thinkingLevel' => $thinking['effort'],
            ], fn ($value) => $value !== null));
        }

        if ($thinking && ($thinking['enabled'] ?? false) && ($provider instanceof OpenAiProvider || $provider instanceof AzureOpenAiProvider)) {
            $providerOptions = $request->providerOptions() ?? [];

            if ($thinking['effort']) {
                $providerOptions['reasoning'] = ['effort' => $thinking['effort']];
            }

            $request = $request->withProviderOptions($providerOptions);
        }

        if ($thinking && $provider instanceof XaiProvider) {
            $request = $request->withProviderOptions([
                'thinking' => ['enabled' => $thinking['enabled']],
            ]);
        }

        if ($thinking && $provider instanceof OllamaProvider) {
            $request = $request->withProviderOptions([
                'thinking' => $thinking['enabled'],
            ]);
        }

        if ($thinking && $provider instanceof DeepSeekProvider) {
            $request = $request->withProviderOptions([
                'thinking' => [
                    'type' => $thinking['enabled'] ? 'enabled' : 'disabled',
                ],
            ]);
        }

        if ($thinking && $provider instanceof GroqProvider) {
            $providerOptions = $request->providerOptions() ?? [];
            $providerOptions['reasoning_format'] = 'parsed';

            if ($thinking['effort']) {
                $providerOptions['reasoning_effort'] = $thinking['effort'];
            }

            $request = $request->withProviderOptions($providerOptions);
        }

        if ($thinking && $provider instanceof MistralProvider) {
            $request = $request->withProviderOptions([
                'prompt_mode' => 'reasoning',
            ]);
        }

        if ($thinking && $provider instanceof OpenRouterProvider) {
            $request = $request->withProviderOptions([
                'include_reasoning' => $thinking['enabled'],
            ]);
        }

        if (! is_null($options?->maxTokens)) {
            $request = $request->withMaxTokens($options->maxTokens);
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
