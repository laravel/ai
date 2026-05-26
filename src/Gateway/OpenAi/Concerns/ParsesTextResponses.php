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
     * @throws AiException
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
        array $requestBody = [],
        ?int $timeout = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            $requestBody,
            maxSteps: $options?->maxSteps,
            options: $options,
            timeout: $timeout,
        );
    }

    protected function processResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $requestBody,
        int $depth = 0,
        ?int $maxSteps = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $responseId = $data['id'] ?? '';
        $output = $data['output'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractText($output);
        $citations = $this->extractCitations($output);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);

        $mappedToolCalls = $this->mapToolCallsWithReasoning($output);

        $step = new Step(
            $text,
            $mappedToolCalls,
            [],
            $finishReason,
            $usage,
            new Meta($provider->name(), $model, $citations),
        );

        $steps->push($step);

        $assistantMessage = new AssistantMessage($text, collect($mappedToolCalls));

        $messages->push($assistantMessage);

        if ($finishReason === FinishReason::ToolCalls &&
            filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            $toolResults = $this->executeToolCalls($mappedToolCalls, $tools);

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
                $provider,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $requestBody,
                $responseId,
                $assistantMessage,
                $toolResultMessage,
                $toolResults,
                $depth + 1,
                $maxSteps,
                $options,
                $timeout,
            );
        }

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
     * The stateful path keeps the original body, swaps input for just the new
     * tool-result items, and adds previous_response_id so OpenAI loads prior
     * state server-side. The stateless path appends the prior turn's items to
     * the running input so OpenAI sees the full conversation each turn. Both
     * paths inherit tools, schema, temperature, top_p, max_output_tokens, and
     * providerOptions from the initial body.
     *
     * @param  array<ToolResult>  $toolResults
     */
    protected function continueWithToolResults(
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $requestBody,
        string $responseId,
        AssistantMessage $assistantMessage,
        ToolResultMessage $toolResultMessage,
        array $toolResults,
        int $depth,
        ?int $maxSteps,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        if ($this->isStateless($provider)) {
            $this->mapAssistantMessage($assistantMessage, $requestBody['input']);
            $this->mapToolResultMessage($toolResultMessage, $requestBody['input']);
        } else {
            $requestBody['previous_response_id'] = $responseId;
            $requestBody['input'] = $this->buildToolResultsInput($toolResults);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $requestBody),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->processResponse(
            $data,
            $provider,
            $structured,
            $tools,
            $schema,
            $steps,
            $messages,
            $requestBody,
            $depth,
            $maxSteps,
            $options,
            $timeout,
        );
    }

    /**
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
                            isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                            isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                        ));
                    }
                }
            }
        }

        return $citations->values();
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
    protected function mapToolCallsWithReasoning(array $output): array
    {
        $toolCalls = [];
        $latestReasoning = null;

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = $item;

                continue;
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_decode($item['arguments'] ?? '{}', true) ?? [],
                    $item['call_id'] ?? null,
                    $latestReasoning ? ($latestReasoning['id'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['summary'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['encrypted_content'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
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
