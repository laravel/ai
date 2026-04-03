<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

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

        $providerOptions = $options?->providerOptions(Lab::Anthropic) ?? [];

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

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

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
