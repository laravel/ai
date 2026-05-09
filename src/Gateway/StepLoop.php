<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

class StepLoop
{
    use InvokesTools;

    public function __construct(
        protected TextGateway $gateway,
        protected Dispatcher $events,
    ) {
        $this->initializeToolCallbacks();
    }

    /**
     * Run the multi-step tool loop, returning a fully resolved TextResponse.
     *
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): TextResponse {
        $steps = new Collection;
        $allMessages = $messages;
        $maxSteps = $options?->maxSteps ?? (count($tools) > 0 ? (int) round(count($tools) * 1.5) : 1);
        $previousResponseId = null;
        $lastResult = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
            );

            $lastResult = $this->gateway->generateText(
                $provider, $model, $instructions, $allMessages, $tools, $schema, $options, $timeout, $previousResponseId, $stepContext,
            );

            if ($lastResult->finishReason !== FinishReason::ToolCalls || empty($lastResult->toolCalls)) {
                $steps->push($this->buildStep($lastResult));

                $allMessages[] = new AssistantMessage(
                    $lastResult->text,
                    new Collection($lastResult->toolCalls),
                    $lastResult->providerContentBlocks,
                );

                break;
            }

            $toolResults = $this->executeToolCalls($lastResult->toolCalls, $tools);

            $steps->push($this->buildStep($lastResult, $toolResults));

            $allMessages[] = new AssistantMessage(
                $lastResult->text,
                new Collection($lastResult->toolCalls),
                $lastResult->providerContentBlocks,
            );

            $allMessages[] = new ToolResultMessage(new Collection($toolResults));

            $previousResponseId = $lastResult->responseId;
        }

        return $this->buildFinalResponse(
            $steps, $allMessages, count($messages), $lastResult,
        );
    }

    /**
     * Stream the multi-step tool loop, yielding events for each turn.
     *
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function stream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $allMessages = $messages;
        $maxSteps = $options?->maxSteps ?? (count($tools) > 0 ? (int) round(count($tools) * 1.5) : 1);
        $previousResponseId = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $pendingToolCalls = [];
            $currentText = '';
            $streamResponseId = null;

            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
            );

            foreach ($this->gateway->streamText($invocationId, $provider, $model, $instructions, $allMessages, $tools, $schema, $options, $timeout, $previousResponseId, $stepContext) as $event) {
                yield $event;

                if ($event instanceof ToolCallEvent) {
                    $pendingToolCalls[] = $event->toolCall;
                }

                if ($event instanceof TextDelta) {
                    $currentText .= $event->delta;
                }

                if ($event instanceof StreamEnd) {
                    $streamResponseId = $event->responseId;
                    break;
                }
            }

            if (empty($pendingToolCalls)) {
                break;
            }

            $toolResults = $this->executeToolCalls($pendingToolCalls, $tools);

            foreach ($toolResults as $toolResult) {
                $event = (new ToolResultEvent(
                    strtolower((string) Str::uuid7()),
                    $toolResult,
                    true,
                    null,
                    time(),
                ))->withInvocationId($invocationId);

                yield $event;
            }

            $allMessages[] = new AssistantMessage(
                $currentText,
                new Collection($pendingToolCalls),
            );

            $allMessages[] = new ToolResultMessage(new Collection($toolResults));

            $previousResponseId = $streamResponseId;
        }
    }

    /**
     * Execute tool calls against the tool registry.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolResult[]
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
     * Build a Step from a single-turn response.
     *
     * @param  ToolResult[]  $toolResults
     */
    protected function buildStep(SingleTurnResponse $result, array $toolResults = []): Step
    {
        return new Step(
            $result->text,
            $result->toolCalls,
            $toolResults,
            $result->finishReason,
            $result->usage,
            $result->meta,
        );
    }

    /**
     * Build the final TextResponse from accumulated steps and messages.
     */
    protected function buildFinalResponse(
        Collection $steps,
        array $allMessages,
        int $originalMessageCount,
        ?SingleTurnResponse $lastResult,
    ): TextResponse {
        $finalStep = $steps->last();

        $totalUsage = $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage,
        );

        $newMessages = (new Collection(array_slice($allMessages, $originalMessageCount)))->values();

        if ($lastResult?->structured !== null && $finalStep instanceof Step) {
            return (new StructuredTextResponse(
                $lastResult->structured,
                $finalStep->text,
                $totalUsage,
                $finalStep->meta,
            ))->withToolCallsAndResults(
                toolCalls: $steps->flatMap(fn (Step $s) => $s->toolCalls),
                toolResults: $steps->flatMap(fn (Step $s) => $s->toolResults),
            )->withSteps($steps);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)->withSteps($steps);
    }
}
