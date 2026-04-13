<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

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
            $body['system_instruction'] = [
                'parts' => [['text' => $instructions]],
            ];
        }

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
            $body['tool_config'] = [
                'function_calling_config' => ['mode' => 'AUTO'],
            ];
        }

        $generationConfig = [];

        if (filled($schema)) {
            $generationConfig['response_mime_type'] = 'application/json';
            $generationConfig['response_json_schema'] = $this->buildResponseSchema($schema);
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
     * @param  array<ToolResult>  $toolResults
     */
    protected function buildFunctionResponseParts(array $toolResults): array
    {
        return array_map(function ($result) {
            $functionResponse = [
                'name' => $result->name,
                'response' => [
                    'name' => $result->name,
                    'content' => $this->serializeToolResultOutput($result->result),
                ],
            ];

            if (filled($result->id)) {
                $functionResponse['id'] = $result->id;
            }

            return ['functionResponse' => $functionResponse];
        }, $toolResults);
    }

    /**
     * Inject the mapped ToolCall id onto each functionCall part in the model
     * response, so the continuation request carries the same id on both
     * functionCall and functionResponse. Gemini matches the two by id, and
     * rejects the request with HTTP 400 when they do not correspond.
     *
     * The pairing is positional: the i-th functionCall part corresponds to the
     * i-th mapped ToolCall, mirroring how extractRawToolCalls walks the parts.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<int, ToolCall>  $mappedToolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function withToolCallIds(array $parts, array $mappedToolCalls): array
    {
        $index = 0;

        return array_map(function (array $part) use ($mappedToolCalls, &$index) {
            if (! isset($part['functionCall'])) {
                return $part;
            }

            $toolCall = $mappedToolCalls[$index] ?? null;
            $index++;

            if ($toolCall === null || ! filled($toolCall->id)) {
                return $part;
            }

            $part['functionCall']['id'] = $toolCall->id;

            return $part;
        }, $parts);
    }

    /**
     * Build the response schema for structured output.
     */
    protected function buildResponseSchema(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }
}
