<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\ToolResultMessage;
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
        $body = [
            'model' => $model,
            'input' => $this->mapMessagesToInput($messages, $instructions),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Build a continuation body using `previous_response_id`, so only the new
     * tool results need to be sent instead of replaying the conversation.
     */
    protected function buildContinuationBody(
        string $previousResponseId,
        string $model,
        array $messages,
        array $tools,
        Provider $provider,
        ?array $schema,
        ?TextGenerationOptions $options = null,
    ): array {
        $body = [
            'model' => $model,
            'previous_response_id' => $previousResponseId,
            'input' => $this->extractToolResultsInput($messages),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    protected function mergeSharedResponsesRequestOptions(
        array $body,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        if (filled($tools)) {
            $body['tool_choice'] = 'auto';
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (filled($schema)) {
            $body['text'] = $this->buildSchemaFormat($schema, Strict::isAppliedTo($options?->agent));
        }

        if ($options?->maxTokens !== null) {
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

        if ($this->isStateless($provider)) {
            $body['store'] = false;

            if ($this->isReasoningModel($body['model'] ?? '')) {
                $body['include'] = array_values(array_unique([
                    ...($body['include'] ?? []),
                    'reasoning.encrypted_content',
                ]));
            }
        }

        return $body;
    }

    protected function extractToolResultsInput(array $messages): array
    {
        $lastMessage = end($messages);

        if (! $lastMessage instanceof ToolResultMessage) {
            return [];
        }

        return collect($lastMessage->toolResults)
            ->map(fn ($toolResult) => [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId,
                'output' => $this->serializeToolResultOutput($toolResult->result),
            ])
            ->all();
    }

    protected function isStateless(Provider $provider): bool
    {
        return filter_var(
            $provider->additionalConfiguration()['store'] ?? true,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === false;
    }

    protected function isReasoningModel(string $model): bool
    {
        return (str_starts_with($model, 'gpt-5') && ! str_starts_with($model, 'gpt-5-chat'))
            || str_starts_with($model, 'o4-mini')
            || str_starts_with($model, 'o3')
            || str_starts_with($model, 'o1');
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
