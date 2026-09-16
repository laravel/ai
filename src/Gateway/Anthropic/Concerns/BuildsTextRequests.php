<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\ToolChoice;

trait BuildsTextRequests
{
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

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        if (filled($instructions)) {
            $body['system'] = $instructions;
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

        if (filled($schema) && $this->supportsNativeStructuredOutput($provider)) {
            $body['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => AnthropicSchemaSanitizer::sanitize(
                        (new ObjectSchema($schema))->toSchema()
                    ),
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
                $body['tool_choice'] = $this->resolveToolChoice($schema, $tools, $providerOptions, $options?->toolChoice);
            }
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        return $this->applyPromptCacheBreakpoints(array_merge($body, $providerOptions), $options);
    }

    /**
     * Stamp the requested cache breakpoints onto the final request body.
     */
    protected function applyPromptCacheBreakpoints(array $body, ?TextGenerationOptions $options): array
    {
        $this->ensureValidPromptCacheOrder($body, $options);

        if (isset($body['system']) && $options?->cacheInstructions instanceof CacheInstructions) {
            $system = is_string($body['system'])
                ? [['type' => 'text', 'text' => $body['system']]]
                : $body['system'];

            $system[array_key_last($system)]['cache_control'] = $this->cacheControl($options->cacheInstructions->ttl);

            $body['system'] = $system;
        }

        if (isset($body['tools']) && $options?->cacheToolDefinitions instanceof CacheToolDefinitions) {
            $body['tools'][array_key_last($body['tools'])]['cache_control'] = $this->cacheControl($options->cacheToolDefinitions->ttl);
        }

        return $body;
    }

    /**
     * Ensure longer-lived cache breakpoints precede shorter-lived breakpoints.
     */
    protected function ensureValidPromptCacheOrder(array $body, ?TextGenerationOptions $options): void
    {
        if ($options?->cacheInstructions?->ttl === '1h'
            && $options->cacheToolDefinitions instanceof CacheToolDefinitions
            && $options->cacheToolDefinitions->ttl !== '1h') {
            throw new InvalidArgumentException('A one-hour instructions cache requires the tool definitions cache to also use a one-hour TTL.');
        }

        if (($body['cache_control']['ttl'] ?? null) === '1h'
            && (($options?->cacheInstructions instanceof CacheInstructions && $options->cacheInstructions->ttl !== '1h')
                || ($options?->cacheToolDefinitions instanceof CacheToolDefinitions && $options->cacheToolDefinitions->ttl !== '1h'))) {
            throw new InvalidArgumentException('A one-hour automatic cache requires all explicit cache breakpoints to also use a one-hour TTL.');
        }
    }

    /**
     * Build the cache control block for the given TTL.
     *
     * @return array<string, string>
     */
    protected function cacheControl(?string $ttl): array
    {
        return array_filter(['type' => 'ephemeral', 'ttl' => $ttl]);
    }

    /**
     * Determine the tool_choice strategy for the request.
     */
    protected function resolveToolChoice(?array $schema, array $tools, array $providerOptions, ?ToolChoice $toolChoice = null): array
    {
        $thinking = isset($providerOptions['thinking']);

        if (filled($schema)) {
            if ($thinking) {
                return ['type' => 'auto'];
            }

            return filled($tools)
                ? ['type' => 'any']
                : ['type' => 'tool', 'name' => 'output_structured_data'];
        }

        if (! $toolChoice instanceof ToolChoice) {
            return ['type' => 'auto'];
        }

        if ($thinking && in_array($toolChoice->mode, [ToolChoice::required, ToolChoice::tool], true)) {
            throw new InvalidArgumentException(
                'Anthropic cannot force tool use while extended thinking is enabled. Use ToolChoice::auto or ToolChoice::none, or disable thinking.'
            );
        }

        return match ($toolChoice->mode) {
            ToolChoice::auto => ['type' => 'auto'],
            ToolChoice::none => ['type' => 'none'],
            ToolChoice::required => ['type' => 'any'],
            ToolChoice::tool => ['type' => 'tool', 'name' => $toolChoice->toolName],
        };
    }

    /**
     * Determine if the provider supports native structured output via output_config.
     */
    protected function supportsNativeStructuredOutput(Provider $provider): bool
    {
        $config = $provider->additionalConfiguration();

        if (array_key_exists('use_native_structured_output', $config)) {
            return (bool) $config['use_native_structured_output'];
        }

        return true;
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
