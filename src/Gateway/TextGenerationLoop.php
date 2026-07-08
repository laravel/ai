<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
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
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Throwable;

class TextGenerationLoop
{
    use InvokesTools;

    public function __construct(protected StepTextGateway $gateway)
    {
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
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

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

            if ($lastResult->finishReason === FinishReason::Continue) {
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
                $tools,
            );

            $shouldContinue = filled($toolResults);

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
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

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

            foreach ($stream as $event) {
                yield $event;

                if ($event instanceof Error) {
                    $sawError = true;
                }
            }

            $result = $stream->getReturn();

            if ($result !== null) {
                $accumulatedUsage = $accumulatedUsage->add($result->usage);
                $finalReason = $result->finishReason;
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
        $pairs = array_map(function (ToolCall $toolCall) use ($tools) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            return [$toolCall, $tool];
        }, $toolCalls);

        $results = [];
        $batch = [];

        foreach ($pairs as $pair) {
            if ($this->isConcurrentTool($pair[1])) {
                $batch[] = $pair;

                continue;
            }

            array_push($results, ...$this->executeConcurrentBatch($batch));
            $batch = [];
            $results[] = $this->executeToolCall(...$pair);
        }

        array_push($results, ...$this->executeConcurrentBatch($batch));

        return $results;
    }

    /**
     * Execute a consecutive batch of concurrency-safe tool calls.
     *
     * @param  array<int, array{ToolCall, Tool}>  $pairs
     * @return ToolResult[]
     */
    protected function executeConcurrentBatch(array $pairs): array
    {
        if (count($pairs) < 2 || ! $this->canRunInParallel()) {
            return array_map(fn (array $pair) => $this->executeToolCall(...$pair), $pairs);
        }

        return $this->executeParallelToolCalls($pairs);
    }

    protected function executeToolCall(ToolCall $toolCall, Tool $tool): ToolResult
    {
        return $this->toolResult($toolCall, $this->executeTool($tool, $toolCall->arguments));
    }

    /**
     * Determine whether the given tool has opted into concurrent execution.
     */
    protected function isConcurrentTool(Tool $tool): bool
    {
        return $tool->isConcurrent();
    }

    /**
     * Execute the given tool calls in parallel, firing invocation events from the parent process.
     *
     * @param  array<int, array{ToolCall, Tool}>  $pairs
     * @return ToolResult[]
     */
    protected function executeParallelToolCalls(array $pairs): array
    {
        // Snapshot the callbacks so a tool that re-registers them mid-batch (e.g. a
        // sub-agent invoked in-process) cannot corrupt the events we fire below.
        $callbacks = $this->pushToolInvocationCallbacks();

        try {
            $invocationIds = array_map(function (array $pair) use ($callbacks) {
                $id = (string) Str::uuid7();

                call_user_func($callbacks['invoking'], $pair[1], $pair[0]->arguments, $id);

                return $id;
            }, $pairs);

            $tasks = array_map(fn (array $pair) => $this->parallelTask(...$pair), $pairs);

            try {
                $envelopes = array_values(Concurrency::run($tasks));
            } catch (Throwable) {
                // Driver can't run here (unavailable, unserializable tools, a mid-batch failure); re-run the whole batch inline — so concurrent tools must be safe to run more than once.
                $envelopes = array_map(fn (Closure $task) => $task(), $tasks);
            }

            $results = [];
            $failure = null;

            foreach ($pairs as $index => [$toolCall, $tool]) {
                $envelope = $envelopes[$index];

                if ($envelope['ok']) {
                    call_user_func($callbacks['invoked'], $tool, $toolCall->arguments, $envelope['result'], $invocationIds[$index]);

                    $results[] = $this->toolResult($toolCall, $envelope['result']);

                    continue;
                }

                $exception = $this->reconstructToolException($envelope['type'], $envelope['message']);

                call_user_func($callbacks['failed'], $tool, $toolCall->arguments, $exception, $invocationIds[$index]);

                $failure ??= $exception;
            }

            if ($failure !== null) {
                throw $failure;
            }

            return $results;
        } finally {
            $this->popToolInvocationCallbacks();
        }
    }

    /**
     * Build the enveloped task that runs a single tool without throwing across the boundary.
     *
     * @return Closure(): (array{ok: true, result: string}|array{ok: false, type: string, message: string})
     */
    protected function parallelTask(ToolCall $toolCall, Tool $tool): Closure
    {
        return function () use ($toolCall, $tool) {
            try {
                return ['ok' => true, 'result' => (string) $tool->handle(new Request($toolCall->arguments))];
            } catch (Throwable $e) {
                return ['ok' => false, 'type' => $e::class, 'message' => $e->getMessage()];
            }
        };
    }

    /**
     * Rebuild a throwable for a tool that failed inside a parallel task.
     */
    protected function reconstructToolException(string $type, string $message): Throwable
    {
        try {
            $exception = new $type($message);
        } catch (Throwable) {
            return new RuntimeException($message);
        }

        return $exception instanceof Throwable ? $exception : new RuntimeException($message);
    }

    /**
     * Create a tool result for the given tool call.
     */
    protected function toolResult(ToolCall $toolCall, string $result): ToolResult
    {
        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $result,
            $toolCall->resultId,
        );
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
