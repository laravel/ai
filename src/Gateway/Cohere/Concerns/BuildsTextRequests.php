<?php

namespace Laravel\Ai\Gateway\Cohere\Concerns;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\ToolChoice;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

    /**
     * Build the request body for the Cohere Chat API.
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
        $body = ['model' => $model];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider);

            if (filled($mappedTools)) {
                $toolChoice = $options?->toolChoice;

                // Cohere cannot force a specific tool, so a named tool choice only offers that tool and requires a call...
                if ($toolChoice instanceof ToolChoice && $toolChoice->mode === ToolChoice::tool) {
                    $mappedTools = array_values(array_filter(
                        $mappedTools,
                        fn (array $tool): bool => ($tool['function']['name'] ?? null) === $toolChoice->toolName,
                    ));

                    if ($mappedTools === []) {
                        throw new InvalidArgumentException("Tool choice [{$toolChoice->toolName}] does not match any of the available tools.");
                    }
                }

                $body['tools'] = $mappedTools;

                if ($toolChoice instanceof ToolChoice && filled($mappedChoice = $this->mapCohereToolChoice($toolChoice))) {
                    $body['tool_choice'] = $mappedChoice;
                }
            }
        }

        $inlineSchema = filled($body['tools'] ?? null) && filled($schema);

        $body['messages'] = $this->mapMessagesToChat(
            $messages,
            $inlineSchema ? $this->composeInstructions($instructions, $schema) : $instructions,
        );

        if (filled($schema) && ! $inlineSchema) {
            $body['response_format'] = [
                'type' => 'json_object',
                'json_schema' => Arr::except((new ObjectSchema($schema))->toSchema(), ['name']),
            ];
        }

        $body = array_merge($body, Arr::whereNotNull([
            'max_tokens' => $options?->maxTokens,
            'temperature' => $options?->temperature,
            'p' => $options?->topP,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Map a tool choice to the Cohere tool_choice value.
     */
    protected function mapCohereToolChoice(ToolChoice $choice): ?string
    {
        return match ($choice->mode) {
            ToolChoice::auto => null,
            ToolChoice::none => 'NONE',
            ToolChoice::required, ToolChoice::tool => 'REQUIRED',
        };
    }
}
