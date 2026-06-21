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
     * Build the request body for a stateful Responses API continuation.
     */
    protected function buildContinuationBody(
        Provider $provider,
        string $model,
        string $continuationToken,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options = null,
    ): array {
        $body = [
            'model' => $model,
            'previous_response_id' => $continuationToken,
            'input' => $this->extractToolResultsInput($messages),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Merge shared Responses API options onto the given request body.
     */
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

    /**
     * Extract the latest tool results for a stateful continuation request.
     */
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

    /**
     * Determine if OpenAI should receive full stateless conversation history.
     */
    protected function isStateless(Provider $provider): bool
    {
        return filter_var(
            $provider->additionalConfiguration()['store'] ?? true,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === false;
    }

    /**
     * Determine if the model supports encrypted reasoning content.
     */
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
