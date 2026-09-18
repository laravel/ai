<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\ToolChoice;

trait BuildsTextRequests
{
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
        return $this->buildTextRequestBody($provider, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Build the request body for the Gemini generateContent API.
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

            if ($options?->toolChoice instanceof ToolChoice) {
                $body['toolConfig'] = [
                    'functionCallingConfig' => $this->functionCallingConfig($options->toolChoice),
                ];
            }
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

        if (is_array($providerOptions['generationConfig'] ?? null)) {
            $providerOptions = array_merge(
                Arr::except($providerOptions, 'generationConfig'),
                $providerOptions['generationConfig'],
            );
        }

        // Hoist keys that need to be passed at top level, as everything else is passed in generationConfig
        $topLevelKeys = ['cachedContent', 'safetySettings', 'toolConfig', 'serviceTier', 'store'];

        foreach ($providerOptions as $key => $value) {
            if (in_array($camelKey = Str::camel($key), $topLevelKeys, true)) {
                $body[$camelKey] = $value;
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
        return array_values(array_map(function ($result): array {
            $functionResponse = [
                'name' => $result->name,
                'response' => [
                    'name' => $result->name,
                    'content' => $result->text(),
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

    /**
     * Map a tool choice to the Gemini functionCallingConfig block.
     *
     * @return array<string, mixed>
     */
    protected function functionCallingConfig(ToolChoice $choice): array
    {
        return match ($choice->mode) {
            ToolChoice::auto => ['mode' => 'AUTO'],
            ToolChoice::none => ['mode' => 'NONE'],
            ToolChoice::required => ['mode' => 'ANY'],
            ToolChoice::tool => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [$choice->toolName],
            ],
        };
    }
}
