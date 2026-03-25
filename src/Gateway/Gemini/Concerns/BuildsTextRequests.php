<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    /**
     * Build the request body for the Gemini generateContent API.
     *
     * Returns a tuple of [request body, contents array] so the contents
     * can be tracked for tool loop history resending.
     */
    protected function buildTextRequestBody(
        Provider $provider,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $contents = $this->mapMessagesToContents($messages);

        return [$this->assembleRequestBody($contents, $instructions, $tools, $schema, $options, $provider), $contents];
    }

    /**
     * Rebuild the request body for a tool-loop continuation.
     */
    protected function rebuildContinuationBody(
        array $contents,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        return $this->assembleRequestBody($contents, $instructions, $tools, $schema, $options, $provider);
    }

    /**
     * Assemble the Gemini request body from the given components.
     */
    private function assembleRequestBody(
        array $contents,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        $body = ['contents' => $contents];

        if (filled($instructions)) {
            $body['systemInstruction'] = [
                'parts' => [['text' => $instructions]],
            ];
        }

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
            $body['toolConfig'] = [
                'functionCallingConfig' => ['mode' => 'AUTO'],
            ];
        }

        $generationConfig = [];

        if (filled($schema)) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $this->buildResponseSchema($schema);
        }

        if (! is_null($options?->maxTokens)) {
            $generationConfig['maxOutputTokens'] = $options->maxTokens;
        }

        if (! is_null($options?->temperature)) {
            $generationConfig['temperature'] = $options->temperature;
        }

        $providerOptions = $options?->providerOptions(Lab::tryFrom($provider->driver()) ?? $provider->driver());

        if (! is_null($providerOptions)) {
            $generationConfig = array_merge($generationConfig, $providerOptions);
        }

        if (filled($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        return $body;
    }

    /**
     * Build function response parts from tool results for the Gemini API.
     *
     * @param  array<\Laravel\Ai\Responses\Data\ToolResult>  $toolResults
     */
    protected function buildFunctionResponseParts(array $toolResults): array
    {
        return array_map(fn ($result) => [
            'functionResponse' => array_filter([
                'name' => $result->name,
                'id' => $result->id,
                'response' => ['content' => $this->serializeToolResultOutput($result->result)],
            ]),
        ], $toolResults);
    }

    /**
     * Build the response schema for structured output.
     */
    protected function buildResponseSchema(array $schema): array
    {
        $objectSchema = new ObjectSchema($schema);

        return Arr::except($objectSchema->toSchema(), ['additionalProperties', 'name']);
    }
}
