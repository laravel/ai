<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    /**
     * Build the request body for the OpenAI Responses API.
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
        $input = $this->mapMessagesToInput($messages, $instructions);

        $body = ['model' => $model, 'input' => $input];

        if (filled($tools)) {
            $body['tool_choice'] = 'auto';
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (filled($schema)) {
            $body['text'] = $this->buildSchemaFormat($schema);
        }

        if (! is_null($options?->maxTokens)) {
            $body['max_output_tokens'] = $options->maxTokens;
        }

        if (! is_null($options?->temperature)) {
            $body['temperature'] = $options->temperature;
        }

        return $body;
    }

    /**
     * Build the text format options for structured output.
     */
    protected function buildSchemaFormat(array $schema): array
    {
        $objectSchema = new ObjectSchema($schema);

        $schemaArray = $objectSchema->toSchema();

        return [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => true,
            ],
        ];
    }
}
