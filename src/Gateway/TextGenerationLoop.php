<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TurnTextGateway;
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
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

class TextGenerationLoop
{
    use InvokesTools;

    public function __construct(
        protected TurnTextGateway $gateway,
    ) {
        $this->initializeToolCallbacks();
    }

    /**
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
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $lastResult = $this->gateway->handleTurn(
                $provider, $model, $instructions, $allMessages, $tools, $schema, $options, $timeout, $stepContext,
            );

            $hasToolCalls = $lastResult->finishReason === FinishReason::ToolCalls && filled($lastResult->toolCalls);
            $shouldContinue = $hasToolCalls && ! $stepContext->isFinalStep;

            $toolResults = $shouldContinue
                ? $this->executeToolCalls($lastResult->toolCalls, $tools)
                : [];

            $shouldContinue = $shouldContinue && filled($toolResults);

            $steps->push($this->buildStep($lastResult, $toolResults));

            $allMessages[] = new AssistantMessage(
                $lastResult->text,
                collect($lastResult->toolCalls),
                $lastResult->providerContentBlocks,
            );

            if (! $shouldContinue) {
                break;
            }

            $allMessages[] = new ToolResultMessage(collect($toolResults));

            $continuationToken = $lastResult->continuationToken;
        }

        return $this->buildFinalResponse($steps, $allMessages, count($messages), $lastResult);
    }

    /**
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
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;
        $sawError = false;

        for ($step = 0; $step < $maxSteps; $step++) {
            $pendingToolCalls = [];
            $currentText = '';
            $turnEnd = null;

            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $turn = $this->gateway->streamTurn(
                $invocationId, $provider, $model, $instructions, $allMessages, $tools, $schema, $options, $timeout, $stepContext,
            );

            foreach ($turn as $event) {
                if ($event instanceof TurnStreamEnd) {
                    $turnEnd = $event;
                    break;
                }

                yield $event;

                if ($event instanceof Error) {
                    $sawError = true;
                } elseif ($event instanceof ToolCallEvent) {
                    $pendingToolCalls[] = $event->toolCall;
                } elseif ($event instanceof TextDelta) {
                    $currentText .= $event->delta;
                }
            }

            if ($turnEnd !== null) {
                $accumulatedUsage = $accumulatedUsage->add($turnEnd->usage);
                $finalReason = $turnEnd->reason;
            }

            $hasToolCalls = $turnEnd?->reason === FinishReason::ToolCalls && filled($pendingToolCalls);
            $shouldContinue = $hasToolCalls && ! $stepContext->isFinalStep;

            $toolResults = $shouldContinue
                ? $this->executeToolCalls($pendingToolCalls, $tools)
                : [];

            $shouldContinue = $shouldContinue && filled($toolResults);

            if ($shouldContinue) {
                foreach ($toolResults as $toolResult) {
                    yield (new ToolResultEvent(
                        strtolower((string) Str::uuid7()),
                        $toolResult,
                        true,
                        null,
                        time(),
                    ))->withInvocationId($invocationId);
                }
            }

            $allMessages[] = new AssistantMessage(
                $currentText,
                collect($pendingToolCalls),
                $turnEnd?->providerContentBlocks ?? [],
            );

            if (! $shouldContinue) {
                break;
            }

            $allMessages[] = new ToolResultMessage(collect($toolResults));

            $continuationToken = $turnEnd?->continuationToken;
        }

        if ($finalReason !== null) {
            yield (new StreamEnd(
                strtolower((string) Str::uuid7()),
                $finalReason->value,
                $accumulatedUsage,
                time(),
            ))->withInvocationId($invocationId);

            return;
        }

        if ($sawError) {
            return;
        }

        yield (new StreamEnd(
            strtolower((string) Str::uuid7()),
            FinishReason::Error->value,
            $accumulatedUsage,
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Tools that delegate to other tools may need more than one round per tool, hence the
     * 1.5x multiplier. The floor of 5 covers tool-less chats that still need a step budget
     * for reasoning or follow-ups. Explicit `maxSteps` via {@see TextGenerationOptions} wins.
     *
     * @param  Tool[]  $tools
     */
    protected function resolveMaxSteps(?TextGenerationOptions $options, array $tools): int
    {
        if ($options?->maxSteps !== null) {
            return max(1, $options->maxSteps);
        }

        return count($tools) > 0 ? (int) round(count($tools) * 1.5) : 5;
    }

    /**
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

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /** @param  ToolResult[]  $toolResults */
    protected function buildStep(TurnResponse $result, array $toolResults = []): Step
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

    protected function buildFinalResponse(
        Collection $steps,
        array $allMessages,
        int $originalMessageCount,
        ?TurnResponse $lastResult,
    ): TextResponse {
        $finalStep = $steps->last();

        $totalUsage = $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage,
        );

        $newMessages = collect(array_slice($allMessages, $originalMessageCount))->values();

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
