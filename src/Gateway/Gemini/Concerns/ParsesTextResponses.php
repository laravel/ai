<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

trait ParsesTextResponses
{
    /**
     * Validate the Gemini response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Gemini Error: [%s] %s',
                $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Gemini error.',
            ));
        }
    }

    /**
     * Parse the Gemini response data into a TextResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        array $contents = [],
        ?string $instructions = null,
        ?int $timeout = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $model,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            $contents,
            $instructions,
            $options,
            maxSteps: $options?->maxSteps,
            timeout: $timeout,
        );
    }

    /**
     * Process a single response, handling tool loops recursively.
     */
    protected function processResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $contents,
        ?string $instructions,
        ?TextGenerationOptions $options,
        int $depth = 0,
        ?int $maxSteps = null,
        ?int $timeout = null,
    ): TextResponse {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $text = $this->extractText($parts);
        $rawToolCalls = $this->extractRawToolCalls($parts);
        $citations = $this->extractCitations($data);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data, $rawToolCalls);

        $mappedToolCalls = $this->mapToolCalls($rawToolCalls);
        $meta = new Meta($provider->name(), $model, $citations);
        $toolResults = [];

        $assistantMessage = new AssistantMessage($text, collect($mappedToolCalls));
        $messages->push($assistantMessage);

        if ($finishReason === FinishReason::ToolCalls &&
            filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            $toolResults = $this->executeToolCalls($mappedToolCalls, $tools);
        }

        $steps->push(new Step($text, $mappedToolCalls, $toolResults, $finishReason, $usage, $meta));

        if (filled($toolResults)) {
            $messages->push(new ToolResultMessage(collect($toolResults)));

            $contents[] = ['role' => 'model', 'parts' => $this->sanitizeRequestParts($this->excludeThinkingParts($parts))];
            $contents[] = ['role' => 'user', 'parts' => $this->buildFunctionResponseParts($toolResults)];

            return $this->continueWithToolResults(
                $model,
                $provider,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $contents,
                $instructions,
                $options,
                $depth + 1,
                $maxSteps,
                $timeout,
            );
        }

        $allToolCalls = $steps->flatMap(fn (Step $s) => $s->toolCalls);
        $allToolResults = $steps->flatMap(fn (Step $s) => $s->toolResults);

        if ($structured) {
            return (new StructuredTextResponse(
                json_decode($text, true) ?? [],
                $text,
                $this->combineUsage($steps),
                $meta,
            ))->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $this->combineUsage($steps),
            $meta,
        ))->withMessages($messages)->withSteps($steps);
    }

    /**
     * Execute tool calls and return tool results.
     *
     * @param  array<ToolCall>  $toolCalls
     * @param  array<Tool>  $tools
     * @return array<ToolResult>
     */
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /**
     * Continue the conversation with tool results by resending the full history.
     */
    protected function continueWithToolResults(
        string $model,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $contents,
        ?string $instructions,
        ?TextGenerationOptions $options,
        int $depth,
        ?int $maxSteps,
        ?int $timeout = null,
    ): TextResponse {
        $body = $this->rebuildContinuationBody($contents, $instructions, $tools, $schema, $options, $provider);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->processResponse(
            $data,
            $provider,
            $model,
            $structured,
            $tools,
            $schema,
            $steps,
            $messages,
            $contents,
            $instructions,
            $options,
            $depth,
            $maxSteps,
            $timeout,
        );
    }

    /**
     * Determine if a response part is a thinking/thought part.
     */
    protected function isThinkingPart(array $part): bool
    {
        return $part['thought'] ?? false;
    }

    /**
     * Sanitize functionCall parts so they can be sent back to Gemini as conversation history.
     */
    protected function sanitizeRequestParts(array $parts): array
    {
        return array_map(function (array $part) {
            if (! isset($part['functionCall'])) {
                return $part;
            }

            $functionCall = ['name' => $part['functionCall']['name'] ?? ''];

            $args = $part['functionCall']['args'] ?? null;

            if (filled($args)) {
                $functionCall['args'] = $args;
            }

            $part['functionCall'] = $functionCall;

            return $part;
        }, $parts);
    }

    /**
     * Filter out thinking parts from the response, keeping only text and functionCall parts.
     */
    protected function excludeThinkingParts(array $parts): array
    {
        return array_values(array_filter(
            $parts,
            fn (array $part) => ! $this->isThinkingPart($part),
        ));
    }

    /**
     * Extract the text content from the response parts, excluding thinking parts.
     */
    protected function extractText(array $parts): string
    {
        $textParts = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && ! $this->isThinkingPart($part)) {
                $textParts[] = $part['text'];
            }
        }

        return implode('', $textParts);
    }

    /**
     * Extract raw tool calls from the response parts.
     */
    protected function extractRawToolCalls(array $parts): array
    {
        return array_values(
            array_map(
                fn (array $part) => $part['functionCall'],
                array_filter($parts, fn (array $part) => isset($part['functionCall']))
            )
        );
    }

    /**
     * Map raw function call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCalls(array $rawToolCalls): array
    {
        return array_map(function (array $fc) {
            $id = $fc['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                $id,
                $fc['name'] ?? '',
                $fc['args'] ?? [],
                $id,
            );
        }, $rawToolCalls);
    }

    /**
     * Extract citations from the response data.
     */
    protected function extractCitations(array $data): Collection
    {
        $citations = new Collection;

        $candidate = $data['candidates'][0] ?? [];

        // Legacy citation metadata format...
        $sources = $candidate['citationMetadata']['citationSources'] ?? [];

        foreach ($sources as $source) {
            if (isset($source['uri'])) {
                $citations->push(new UrlCitation(
                    $source['uri'],
                    $source['title'] ?? null,
                ));
            }
        }

        // Grounding metadata format (Google Search grounding)...
        $groundingChunks = $candidate['groundingMetadata']['groundingChunks'] ?? [];
        $groundingSupports = $candidate['groundingMetadata']['groundingSupports'] ?? [];

        $referencedIndices = [];

        foreach ($groundingSupports as $support) {
            foreach ($support['groundingChunkIndices'] ?? [] as $index) {
                $referencedIndices[$index] = true;
            }
        }

        foreach ($referencedIndices as $index => $_) {
            $web = $groundingChunks[$index]['web'] ?? [];

            if (isset($web['uri'])) {
                $citations->push(new UrlCitation(
                    $web['uri'],
                    $web['title'] ?? null,
                ));
            }
        }

        return $citations->unique('url')->values();
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usageMetadata'] ?? [];

        $promptTokens = $usage['promptTokenCount'] ?? 0;
        $cachedTokens = $usage['cachedContentTokenCount'] ?? 0;

        return new Usage(
            $promptTokens - $cachedTokens,
            $usage['candidatesTokenCount'] ?? 0,
            0,
            $cachedTokens,
            $usage['thoughtsTokenCount'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Gemini response.
     */
    protected function extractFinishReason(array $data, array $rawToolCalls): FinishReason
    {
        if (filled($rawToolCalls)) {
            return FinishReason::ToolCalls;
        }

        $candidate = $data['candidates'][0] ?? [];
        $reason = $candidate['finishReason'] ?? '';

        return match ($reason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Combine usage across all steps.
     */
    protected function combineUsage(Collection $steps): Usage
    {
        return $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage(0, 0)
        );
    }
}
