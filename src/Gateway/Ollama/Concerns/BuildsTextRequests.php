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
        $body = [
            'model' => $model,
            'messages' => $this->mapMessagesToChat($messages, $instructions),
            'stream' => false,
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
