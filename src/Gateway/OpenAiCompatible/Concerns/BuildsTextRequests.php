<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

    /**
     * Build the request body for the current text generation step.
     */
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $config = $provider->additionalConfiguration();

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

        $inlineSchema = filled($schema) && $this->shouldInlineSchema($config['inline_schema'] ?? 'never', $hasTools);

        $body['messages'] = $this->mapMessagesToChat(
            $messages,
            $inlineSchema ? $this->composeInstructions($instructions, $schema) : $instructions,
        );

        if (filled($schema) && ! $inlineSchema) {
            $body['response_format'] = $this->buildResponseFormat($schema, $config);
        }

        if (! is_null($options?->maxTokens)) {
            $body[$config['max_tokens_field'] ?? 'max_tokens'] = $options->maxTokens;
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
     * Determine whether the schema should be inlined into the instructions.
     */
    protected function shouldInlineSchema(string $mode, bool $hasTools): bool
    {
        return match ($mode) {
            'always' => true,
            'when_tools' => $hasTools,
            default => false,
        };
    }

    /**
     * Build the response format options for structured output.
     */
    protected function buildResponseFormat(array $schema, array $config): array
    {
        if (($config['response_format'] ?? 'json_schema') === 'json_object') {
            return ['type' => 'json_object'];
        }

        $schemaArray = (new ObjectSchema($schema))->toSchema();

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
