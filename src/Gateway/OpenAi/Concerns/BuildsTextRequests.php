<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Attributes\Strict;
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
            $body['text'] = $this->buildSchemaFormat($schema, Strict::isAppliedTo($options?->agent));
        }

        if (! is_null($options?->maxTokens)) {
            $body['max_output_tokens'] = $options->maxTokens;
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            $body = array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Build the request body for a tool-result follow-up request.
     *
     * Zero Data Retention accounts reject `previous_response_id`, so the
     * full conversation is sent inline instead of relying on server-side chaining.
     */
    protected function buildToolFollowUpBody(
        string $model,
        string $responseId,
        bool $zeroDataRetention,
        array $toolResults,
        array $messages = [],
        ?string $instructions = null,
    ): array {
        if ($zeroDataRetention) {
            return [
                'model' => $model,
                'input' => $this->mapMessagesToInput($messages, $instructions),
            ];
        }

        return [
            'model' => $model,
            'previous_response_id' => $responseId,
            'input' => $this->buildToolResultsInput($toolResults),
        ];
    }

    /**
     * Build the text format options for structured output.
     */
    protected function buildSchemaFormat(array $schema, bool $strict): array
    {
        $schemaArray = (new ObjectSchema($schema, strict: $strict))->toSchema();

        return [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => $strict,
            ],
        ];
    }
}
