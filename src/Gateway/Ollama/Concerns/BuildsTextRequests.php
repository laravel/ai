<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    /**
     * Build the request body for the Ollama Chat API.
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
        return $this->buildChatRequestBody(
            $provider,
            $model,
            $this->mapMessagesToChat($messages, $instructions),
            $tools,
            $schema,
            $options,
        );
    }

    /**
     * Build a request body from pre-mapped chat messages.
     */
    protected function buildChatRequestBody(
        Provider $provider,
        string $model,
        array $chatMessages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        bool $stream = false,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $chatMessages,
            'stream' => $stream,
        ];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (filled($mappedTools)) {
                $body['tools'] = $mappedTools;
            }
        }

        if (filled($schema)) {
            $body['format'] = $this->buildResponseFormat($schema);
        }

        $ollamaOptions = Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'num_predict' => $options?->maxTokens,
        ]);

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        $mergedOptions = array_merge($ollamaOptions, $providerOptions);

        if (filled($mergedOptions)) {
            $body['options'] = $mergedOptions;
        }

        return $body;
    }

    /**
     * Build the response format schema for structured output.
     */
    protected function buildResponseFormat(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }
}
