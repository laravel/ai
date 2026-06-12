<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
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
        }

        $generationConfig = [];

        if (filled($schema)) {
            $generationConfig['response_mime_type'] = 'application/json';
            $generationConfig['response_json_schema'] = $this->buildResponseSchema($schema);
        }

        if (! is_null($options?->maxTokens)) {
            $generationConfig['maxOutputTokens'] = $options->maxTokens;
        }

        $generationConfig = array_merge($generationConfig, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'topP' => $options?->topP,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        // Hoist keys that need to be passed at top level, as everything else is passed in generationConfig
        $topLevelKeys = ['cachedContent'];
        foreach ($topLevelKeys as $key) {
            if (array_key_exists($key, $providerOptions)) {
                $body[$key] = $providerOptions[$key];
                unset($providerOptions[$key]);
            }
        }

        if (filled($providerOptions)) {
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
        return array_values(array_map(function ($result) {
            $functionResponse = [
                'name' => $result->name,
                'response' => [
                    'name' => $result->name,
                    'content' => $this->serializeToolResultOutput($result->result),
                ],
            ];

            if ($result->id !== null) {
                $functionResponse['id'] = $result->id;
            }

            return ['functionResponse' => $functionResponse];
        }, $toolResults));
    }

    /**
     * Build the response schema for structured output.
     */
    protected function buildResponseSchema(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }
}
