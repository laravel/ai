<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Support\Arr;
use InvalidArgumentException;
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

        if (filled($instructions)) {
            $body['system'] = $instructions;
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

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
                $body['tool_choice'] = $this->resolveToolChoice($schema, $tools, $providerOptions, $options?->toolChoice);
            }
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        return array_merge($body, $providerOptions);
    }

    /**
     * Determine the tool_choice strategy, rejecting a forced tool while thinking is enabled since Anthropic only allows "auto" or "none" in that mode.
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

        if (! $toolChoice) {
            return ['type' => 'auto'];
        }

        if ($thinking && in_array($toolChoice->mode, [ToolChoice::required, ToolChoice::tool], true)) {
            throw new InvalidArgumentException(
                'Anthropic cannot force tool use while extended thinking is enabled. Use ToolChoice::auto() or ToolChoice::none(), or disable thinking.'
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
