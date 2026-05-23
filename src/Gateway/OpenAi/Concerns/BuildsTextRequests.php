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
        $input = $this->mapMessagesToInput($messages, $instructions);

        $body = ['model' => $model, 'input' => $input];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Build a lightweight continuation body using previous_response_id.
     *
     * Only sends tool results as input instead of replaying the full conversation.
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

    /**
     * Apply options shared by initial and continuation requests.
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
     * Extract tool result items from the trailing messages for a continuation request.
     */
    protected function extractToolResultsInput(array $messages): array
    {
        $input = [];

        $lastMessage = end($messages);

        if ($lastMessage instanceof ToolResultMessage) {
            foreach ($lastMessage->toolResults as $toolResult) {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $toolResult->resultId,
                    'output' => $this->serializeToolResultOutput($toolResult->result),
                ];
            }
        }

        return $input;
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
