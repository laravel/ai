<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StepFinished;
use Laravel\Ai\Events\StepStarted;
use Laravel\Ai\Exceptions\NoSuchToolException;
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
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Throwable;

class TextGenerationLoop
{
    use InvokesTools;

    public function __construct(
        protected StepTextGateway $gateway,
        protected Dispatcher $events,
    ) {
        $this->initializeToolCallbacks();
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $steps = new Collection;
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepId = strtolower((string) Str::uuid7());

            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $this->events->dispatch(new StepStarted($invocationId, $stepId, $step, $model, $options));

            try {
                $lastResult = $this->gateway->generateTextStep(
                    $provider,
                    $model,
                    $instructions,
                    $allMessages,
                    $tools,
                    $schema,
                    $options,
                    $timeout,
                    $stepContext,
                );
            } catch (Throwable $e) {
                $this->events->dispatch(new StepFailed($invocationId, $stepId, $step, $e));
                throw $e;
            }

            if ($lastResult->finishReason === FinishReason::Continue) {
                $this->events->dispatch(new StepFinished($invocationId, $stepId, $step, $lastResult));

                $steps->push($this->buildStep($lastResult));

                $allMessages[] = new AssistantMessage(
                    $lastResult->text,
                    collect($lastResult->toolCalls),
                    $lastResult->providerContentBlocks,
                );

                $continuationToken = $lastResult->continuationToken;

                continue;
            }

            $toolResults = $this->continuationToolResults(
                $lastResult->finishReason,
                $lastResult->toolCalls,
                $stepContext->isFinalStep,
                $tools
            );

            $shouldContinue = filled($toolResults);

            $this->events->dispatch(new StepFinished($invocationId, $stepId, $step, $lastResult));

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
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;
        $sawError = false;

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepId = strtolower((string) Str::uuid7());

            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $this->events->dispatch(new StepStarted($invocationId, $stepId, $step, $model, $options));

            try {
                $stream = $this->gateway->generateStreamStep(
                    $invocationId,
                    $provider,
                    $model,
                    $instructions,
                    $allMessages,
                    $tools,
                    $schema,
                    $options,
                    $timeout,
                    $stepContext,
                );

                foreach ($stream as $streamEvent) {
                    yield $streamEvent;

                    if ($streamEvent instanceof Error) {
                        $sawError = true;
                    }
                }

                $result = $stream->getReturn();
            } catch (Throwable $e) {
                $this->events->dispatch(new StepFailed($invocationId, $stepId, $step, $e));
                throw $e;
            }

            if ($result !== null) {
                $accumulatedUsage = $accumulatedUsage->add($result->usage);
                $finalReason = $result->finishReason;

                $this->events->dispatch(new StepFinished($invocationId, $stepId, $step, $result));
            }

            if ($result?->finishReason === FinishReason::Continue) {
                $allMessages[] = new AssistantMessage(
                    $result->text,
                    collect($result->toolCalls),
                    $result->providerContentBlocks,
                );

                $continuationToken = $result->continuationToken;

                continue;
            }

            $toolResults = $result !== null
                ? $this->continuationToolResults($result->finishReason, $result->toolCalls, $stepContext->isFinalStep, $tools)
                : [];

            $shouldContinue = filled($toolResults);

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
                $result?->text ?? '',
                collect($result?->toolCalls ?? []),
                $result?->providerContentBlocks ?? [],
            );

            if (! $shouldContinue) {
                break;
            }

            $allMessages[] = new ToolResultMessage(collect($toolResults));

            $continuationToken = $result?->continuationToken;
        }

        $reason = $finalReason ?? ($sawError ? null : FinishReason::Error);

        if ($reason !== null) {
            yield (new StreamEnd(
                strtolower((string) Str::uuid7()),
                $reason->value,
                $accumulatedUsage,
                time(),
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Resolve the step budget: explicit `maxSteps`, else 1.5x tools, else 5.
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
     * Tool results to continue the loop with, or [] when this step should be the last.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolResult[]
     */
    protected function continuationToolResults(FinishReason $reason, array $toolCalls, bool $isFinalStep, array $tools): array
    {
        return $reason === FinishReason::ToolCalls && ! $isFinalStep && filled($toolCalls)
            ? $this->executeToolCalls($toolCalls, $tools)
            : [];
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolResult[]
     */
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        return array_map(function (ToolCall $toolCall) use ($tools) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
                $toolCall->resultId,
            );
        }, $toolCalls);
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function buildStep(StepResponse $result, array $toolResults = []): Step
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
     * Build the final text response from all generated steps.
     */
    protected function buildFinalResponse(
        Collection $steps,
        array $allMessages,
        int $originalMessageCount,
        ?StepResponse $lastResult,
    ): TextResponse {
        $finalStep = $steps->last();

        $totalUsage = $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage,
        );

        $newMessages = collect(array_slice($allMessages, $originalMessageCount))->values();

        if ($lastResult?->structured !== null) {
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
