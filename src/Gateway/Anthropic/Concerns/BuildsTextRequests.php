<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    /**
     * Internal request-body key used to carry `tool_result_cache_control` across
     * recursive tool-loop follow-up requests. Stripped before the HTTP send.
     */
    private const INTERNAL_TOOL_RESULT_CACHE_KEY = '__sdk_tool_result_cache_control';

    protected function stripInternalKeys(array $body): array
    {
        unset($body[self::INTERNAL_TOOL_RESULT_CACHE_KEY]);

        return $body;
    }

    /**
     * Build the request body for the Anthropic Messages API.
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $this->mapMessages($messages),
            'max_tokens' => $options?->maxTokens ?? 64_000,
        ];

        if (filled($instructions)) {
            $body['system'] = $instructions;
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

        $providerOptions = $options?->providerOptions(Lab::Anthropic) ?? [];

        if (isset($providerOptions['cache_control'])) {
            if (isset($body['system']) && is_string($body['system'])) {
                $body['system'] = [[
                    'type' => 'text',
                    'text' => $body['system'],
                    'cache_control' => $providerOptions['cache_control'],
                ]];
            }

            unset($providerOptions['cache_control']);
        }

        if (isset($providerOptions['tool_result_cache_control'])) {
            $cacheControl = $providerOptions['tool_result_cache_control'];
            $body['messages'] = $this->applyToolResultCacheControl($body['messages'], $cacheControl);
            $body[self::INTERNAL_TOOL_RESULT_CACHE_KEY] = $cacheControl;

            unset($providerOptions['tool_result_cache_control']);
        }

        if (filled($schema) && $this->supportsNativeStructuredOutput($provider)) {
            $body['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => (new ObjectSchema($schema))->toSchema(),
                ],
            ];

            if (filled($mappedTools)) {
                $body['tools'] = $mappedTools;
                $body['tool_choice'] = ['type' => 'auto'];
            }
        } else {
            if (filled($schema)) {
                $mappedTools[] = $this->buildStructuredOutputTool($schema);
            }

            if (filled($mappedTools)) {
                $body['tools'] = $mappedTools;
                $body['tool_choice'] = $this->resolveToolChoice($schema, $tools, $providerOptions);
            }
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        return array_merge($body, $providerOptions);
    }

    /**
     * Determine the tool_choice strategy for the request.
     *
     * Thinking mode only supports "auto" -- forced tool selection causes an API error.
     *
     * Without thinking: structured-only forces the synthetic tool, tools+schema uses "any".
     */
    protected function resolveToolChoice(?array $schema, array $tools, array $providerOptions): array
    {
        if (! filled($schema) || isset($providerOptions['thinking'])) {
            return ['type' => 'auto'];
        }

        return filled($tools)
            ? ['type' => 'any']
            : ['type' => 'tool', 'name' => 'output_structured_data'];
    }

    /**
     * Determine if the provider supports native structured output via output_config.
     */
    protected function supportsNativeStructuredOutput(Provider $provider): bool
    {
        $beta = $provider->additionalConfiguration()['anthropic_beta'] ?? '';

        return str_contains($beta, 'structured-outputs');
    }

    /**
     * Attach a cache_control breakpoint to the last tool_result content block
     * across the mapped messages. Used by the `tool_result_cache_type` shorthand.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $cacheControl
     * @return array<int, array<string, mixed>>
     */
    protected function applyToolResultCacheControl(array $messages, array $cacheControl): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (! isset($messages[$i]['content']) || ! is_array($messages[$i]['content'])) {
                continue;
            }

            for ($j = count($messages[$i]['content']) - 1; $j >= 0; $j--) {
                if (($messages[$i]['content'][$j]['type'] ?? null) === 'tool_result') {
                    $messages[$i]['content'][$j]['cache_control'] = $cacheControl;

                    return $messages;
                }
            }
        }

        return $messages;
    }

    /**
     * Build the synthetic tool definition for structured output.
     */
    protected function buildStructuredOutputTool(array $schema): array
    {
        $schemaArray = (new ObjectSchema($schema))->toSchema();

        return [
            'name' => 'output_structured_data',
            'description' => 'Output the structured data matching the required schema.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) ($schemaArray['properties'] ?? []),
                'required' => $schemaArray['required'] ?? [],
            ],
        ];
    }
}
