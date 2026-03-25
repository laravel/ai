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

        if (filled($tools) || filled($schema)) {
            $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

            if (filled($schema)) {
                $mappedTools[] = $this->buildStructuredOutputTool($schema);

                // When both regular tools and schema are present, force the model
                // to always use a tool. It will pick the real tool when needed,
                // then output_structured_data when done. When only structured
                // output exists, force the specific tool directly.
                $body['tool_choice'] = filled($tools)
                    ? ['type' => 'any']
                    : ['type' => 'tool', 'name' => 'output_structured_data'];
            } elseif (filled($mappedTools)) {
                $body['tool_choice'] = ['type' => 'auto'];
            }

            if (filled($mappedTools)) {
                $body['tools'] = $mappedTools;
            }
        }

        if (! is_null($options?->temperature)) {
            $body['temperature'] = $options->temperature;
        }

        $providerOptions = $options?->providerOptions(Lab::Anthropic);

        if (! is_null($providerOptions)) {
            $body = array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Build the synthetic tool definition for structured output.
     */
    protected function buildStructuredOutputTool(array $schema): array
    {
        $objectSchema = new ObjectSchema($schema);

        $schemaArray = $objectSchema->toSchema();

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
