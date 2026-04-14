<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

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
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

trait ParsesTextResponses
{
    /**
     * Validate the Ollama response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Ollama Error: %s',
                $data['error'] ?? 'Unknown Ollama error.',
            ));
        }
    }

    /**
     * Parse the Ollama response data into a TextResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?string $instructions = null,
        array $originalMessages = [],
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
            instructions: $instructions,
            originalMessages: $originalMessages,
            maxSteps: $options?->maxSteps,
            options: $options,
            timeout: $timeout,
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
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $message = $data['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);

        $mappedToolCalls = array_map(function (array $toolCall) {
            $id = $toolCall['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                id: $id,
                name: $toolCall['function']['name'] ?? '',
                arguments: $this->parseToolArguments($toolCall['function']['arguments'] ?? []),
                resultId: $toolCall['id'] ?? null,
            );
        }, $rawToolCalls);

        $step = new Step(
            $text,
            $mappedToolCalls,
            [],
            $finishReason,
            $usage,
            new Meta($provider->name(), $model),
        );

        $steps->push($step);

        $assistantMessage = new AssistantMessage($text, collect($mappedToolCalls));

        $messages->push($assistantMessage);

        if (filled($mappedToolCalls) &&
            $steps->count() < ($maxSteps ?? round(count($tools) * 1.5))) {
            $toolResults = $this->executeToolCalls($mappedToolCalls, $tools);

            $steps->pop();

            $steps->push(new Step(
                $text,
                $mappedToolCalls,
                $toolResults,
                $finishReason,
                $usage,
                new Meta($provider->name(), $model),
            ));

            $toolResultMessage = new ToolResultMessage(collect($toolResults));

            $messages->push($toolResultMessage);

            return $this->continueWithToolResults(
                $model,
                $provider,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $instructions,
                $originalMessages,
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
                new Meta($provider->name(), $model),
            ))->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $this->combineUsage($steps),
            new Meta($provider->name(), $model),
        ))->withMessages($messages)->withSteps($steps);
    }

    /**
     * Parse tool call arguments, handling both array and JSON string formats.
     */
    protected function parseToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return json_decode($arguments ?? '{}', true) ?? [];
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
        string $model,
        Provider $provider,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $chatMessages = $this->mapMessagesToChat($originalMessages, $instructions);

        foreach ($messages as $msg) {
            if ($msg instanceof AssistantMessage) {
                $mapped = [
                    'role' => 'assistant',
                    'content' => $msg->content ?? '',
                ];

                if ($msg->toolCalls->isNotEmpty()) {
                    $mapped['tool_calls'] = $msg->toolCalls->map(
                        fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall)
                    )->all();
                }

                $chatMessages[] = $mapped;
            } elseif ($msg instanceof ToolResultMessage) {
                foreach ($msg->toolResults as $toolResult) {
                    $chatMessages[] = [
                        'role' => 'tool',
                        'tool_name' => $toolResult->name,
                        'content' => $this->serializeToolResultOutput($toolResult->result),
                    ];
                }
            }
        }

        $body = $this->buildChatRequestBody(
            $provider,
            $model,
            $chatMessages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('api/chat', $body),
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
            $instructions,
            $originalMessages,
            $depth,
            $maxSteps,
            $options,
            $timeout,
        );
    }

    /**
     * Extract usage data from the Ollama response.
     */
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Ollama response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['done_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
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
