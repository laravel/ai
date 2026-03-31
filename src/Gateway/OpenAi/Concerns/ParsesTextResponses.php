<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Collection;
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
     * Validate the OpenAI response data.
     *
     * @throws \Laravel\Ai\Exceptions\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse the OpenAI response data into a TextResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            maxSteps: $options?->maxSteps,
        );
    }

    /**
     * Process a single response, handling tool loops recursively.
     */
    protected function processResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        int $depth = 0,
        ?int $maxSteps = null,
    ): TextResponse {
        $responseId = $data['id'] ?? '';
        $output = $data['output'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractText($output);
        $toolCalls = $this->extractToolCalls($output);
        $reasonings = $this->extractReasonings($output);
        $citations = $this->extractCitations($output);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);

        // Associate reasoning with tool calls...
        $mappedToolCalls = $this->mapToolCallsWithReasoning($toolCalls, $reasonings);

        $step = new Step(
            $text,
            $mappedToolCalls,
            [],
            $finishReason,
            $usage,
            new Meta($provider->name(), $model, $citations),
        );

        $steps->push($step);

        // Build assistant message for conversation context...
        $assistantMessage = new AssistantMessage($text, collect($mappedToolCalls));

        $messages->push($assistantMessage);

        // Execute tool calls...
        if ($finishReason === FinishReason::ToolCalls &&
            filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            $toolResults = $this->executeToolCalls($mappedToolCalls, $tools);

            // Update step with tool results...
            $steps->pop();

            $steps->push(new Step(
                $text,
                $mappedToolCalls,
                $toolResults,
                $finishReason,
                $usage,
                new Meta($provider->name(), $model, $citations),
            ));

            $toolResultMessage = new ToolResultMessage(collect($toolResults));

            $messages->push($toolResultMessage);

            return $this->continueWithToolResults(
                $responseId, $model, $provider, $structured, $tools, $schema, $steps, $messages, $toolResults, $depth + 1, $maxSteps,
            );
        }

        // Build final response...
        $allToolCalls = $steps->flatMap(fn (Step $s) => $s->toolCalls);
        $allToolResults = $steps->flatMap(fn (Step $s) => $s->toolResults);

        if ($structured) {
            $structuredData = json_decode($text, true) ?? [];

            return (new StructuredTextResponse(
                $structuredData,
                $text,
                $this->combineUsage($steps),
                new Meta($provider->name(), $model, $citations),
            ))->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $this->combineUsage($steps),
            new Meta($provider->name(), $model, $citations),
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
     * Continue the conversation with tool results by making a follow-up request.
     */
    protected function continueWithToolResults(
        string $responseId,
        string $model,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $toolResults,
        int $depth,
        ?int $maxSteps,
    ): TextResponse {
        $body = [
            'model' => $model,
            'previous_response_id' => $responseId,
            'input' => $this->buildToolResultsInput($toolResults),
        ];

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (filled($schema)) {
            $body['text'] = $this->buildSchemaFormat($schema);
        }

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->processResponse($data, $provider, $structured, $tools, $schema, $steps, $messages, $depth, $maxSteps);
    }

    /**
     * Build the input array containing only tool results for a follow-up request.
     *
     * @param  array<ToolResult>  $toolResults
     */
    protected function buildToolResultsInput(array $toolResults): array
    {
        $input = [];

        foreach ($toolResults as $result) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $result->resultId,
                'output' => $this->serializeToolResultOutput($result->result),
            ];
        }

        return $input;
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? json_encode($output) : strval($output);
    }

    /**
     * Extract the text content from the output array.
     */
    protected function extractText(array $output): string
    {
        $lastOutput = last($output);

        if (is_array($lastOutput)) {
            return $lastOutput['content'][0]['text'] ?? '';
        }

        return '';
    }

    /**
     * Extract tool calls from the output array.
     */
    protected function extractToolCalls(array $output): array
    {
        return array_values(array_filter($output, fn (array $item) => ($item['type'] ?? '') === 'function_call'));
    }

    /**
     * Extract reasoning blocks from the output array.
     */
    protected function extractReasonings(array $output): array
    {
        return array_values(array_filter($output, fn (array $item) => ($item['type'] ?? '') === 'reasoning'));
    }

    /**
     * Extract citations from the output array.
     */
    protected function extractCitations(array $output): Collection
    {
        $citations = new Collection;

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $citations->push(new UrlCitation(
                            $annotation['url'] ?? '',
                            $annotation['title'] ?? null,
                        ));
                    }
                }
            }
        }

        return $citations->unique('title')->values();
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cachedTokens = $usage['input_tokens_details']['cached_tokens'] ?? 0;

        return new Usage(
            $inputTokens - $cachedTokens,
            $usage['output_tokens'] ?? 0,
            0,
            $cachedTokens,
            $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the OpenAI response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $lastOutput = last($data['output'] ?? []);
        $status = $lastOutput['status'] ?? $data['status'] ?? '';
        $type = $lastOutput['type'] ?? '';

        return match ($status) {
            'incomplete' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string) $type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }

    /**
     * Map tool calls with their associated reasoning blocks.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCallsWithReasoning(array $toolCalls, array $reasonings): array
    {
        $firstReasoning = $reasonings[0] ?? null;

        return array_map(fn (array $tc) => new ToolCall(
            $tc['id'] ?? '',
            $tc['name'] ?? '',
            json_decode($tc['arguments'] ?? '{}', true) ?? [],
            $tc['call_id'] ?? null,
            $firstReasoning ? $firstReasoning['id'] ?? null : null,
            $firstReasoning ? $firstReasoning['summary'] ?? null : null,
        ), $toolCalls);
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
