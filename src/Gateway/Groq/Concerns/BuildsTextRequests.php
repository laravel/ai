<?php

namespace Laravel\Ai\Gateway\Groq\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

    /**
     * Build the request body for the Chat Completions API.
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
        $hasTools = false;

        $body = ['model' => $model];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (filled($mappedTools)) {
                $body['tool_choice'] = 'auto';
                $body['tools'] = $mappedTools;
                $hasTools = true;
            }
        }

        $inlineSchema = $this->shouldInlineSchemaInInstructions($hasTools, $schema);

        $body['messages'] = $this->mapMessagesToChat(
            $messages,
            $inlineSchema ? $this->composeInstructions($instructions, $schema) : $instructions,
        );

        if (filled($schema) && ! $inlineSchema) {
            $body['response_format'] = $this->buildResponseFormat($schema);
        }

        if (! is_null($options?->maxTokens)) {
            $body['max_completion_tokens'] = $options->maxTokens;
        }

        if (! is_null($options?->temperature)) {
            $body['temperature'] = $options->temperature;
        }

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            $body = array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Whether the schema must be embedded in the system instructions instead
     * of being sent as `response_format`. Groq's API rejects requests that
     * combine `response_format` with tool use.
     */
    protected function shouldInlineSchemaInInstructions(bool $hasTools, ?array $schema): bool
    {
        return $hasTools && filled($schema);
    }

    /**
     * Build the response format options for structured output.
     */
    protected function buildResponseFormat(array $schema): array
    {
        $objectSchema = new ObjectSchema($schema);

        $schemaArray = $objectSchema->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => true,
            ],
        ];
    }
}
