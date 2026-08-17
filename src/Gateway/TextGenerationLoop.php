<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

use function Laravel\Ai\pipeline;

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
        $middleware = $options?->middleware() ?? [];
        $continuationToken = null;
        $lastResult = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $pending = new PendingStep(
                $provider, $model, $instructions, $allMessages, $tools,
                $schema, $options?->forStep($step), $timeout, $stepContext,
            );

            $lastResult = $this->sendThroughMiddleware($pending, $middleware, fn (PendingStep $step) => $this->gateway->generateTextStep(
                $step->provider,
                $step->model,
                $step->instructions,
                $step->messages,
                $step->tools,
                $step->schema,
                $step->options,
                $step->timeout,
                $step->context,
            ));

            $lastResult = $this->resolveShortCircuitedStep($pending, $lastResult);

            $maxSteps = max($maxSteps, $this->resolveMaxSteps($pending->options, $pending->tools));

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
                $pending->tools
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
        $middleware = $options?->middleware() ?? [];
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

            $pending = new PendingStep(
                $provider, $model, $instructions, $allMessages, $tools,
                $schema, $options?->forStep($step), $timeout, $stepContext, streaming: true,
            );

            $outcome = $this->sendThroughMiddleware($pending, $middleware, fn (PendingStep $step) => $this->gateway->generateStreamStep(
                $invocationId,
                $step->provider,
                $step->model,
                $step->instructions,
                $step->messages,
                $step->tools,
                $step->schema,
                $step->options,
                $step->timeout,
                $step->context,
            ));

            $maxSteps = max($maxSteps, $this->resolveMaxSteps($pending->options, $pending->tools));

            if ($outcome instanceof StepResponse) {
                $result = $outcome;

                yield from $this->shortCircuitedStepEvents($invocationId, $pending, $result);
            } else {
                foreach ($outcome as $event) {
                    yield $event;

                    if ($event instanceof Error) {
                        $sawError = true;
                    }
                }

                $result = $outcome->getReturn();
            }

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
                ? $this->continuationToolResults($result->finishReason, $result->toolCalls, $stepContext->isFinalStep, $pending->tools)
                : [];

            $shouldContinue = filled($toolResults);

            if ($shouldContinue) {
                foreach ($toolResults as $toolResult) {
                    yield (new ToolResultEvent(
                        $this->generateEventId(),
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
                $this->generateEventId(),
                $reason->value,
                $accumulatedUsage,
                time(),
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Run the pending step through the given middleware and into the gateway, tracking the step each middleware receives so short-circuited tool calls execute against it.
     */
    protected function sendThroughMiddleware(PendingStep &$pending, array $middleware, Closure $call): mixed
    {
        $call = function (PendingStep $step) use ($call, &$pending) {
            return $call($pending = $step);
        };

        if ($middleware === []) {
            return $call($pending);
        }

        $through = [];

        foreach ($middleware as $pipe) {
            $through[] = function (PendingStep $step, $next) use ($pipe, &$pending) {
                return $pipe->handle($pending = $step, $next);
            };
        }

        $outcome = pipeline()
            ->send($pending)
            ->through($through)
            ->then($call);

        if ($outcome instanceof PendingStep) {
            throw new InvalidArgumentException('Agent middleware must return $next($step) or a StepResponse.');
        }

        return $outcome;
    }

    /**
     * Backfill the structured output of a short-circuited step by decoding its text when the step requested a schema.
     */
    protected function resolveShortCircuitedStep(PendingStep $pending, StepResponse $result): StepResponse
    {
        if ($pending->schema !== null && $result->structured === null) {
            $structured = json_decode($result->text, true);

            $result->structured = is_array($structured) ? $structured : null;
        }

        return $result;
    }

    /**
     * Generate a lowercased UUIDv7 for a stream event id.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    /**
     * Synthesize the stream events for a step that middleware short-circuited.
     */
    protected function shortCircuitedStepEvents(string $invocationId, PendingStep $pending, StepResponse $result): Generator
    {
        $messageId = $this->generateEventId();

        yield (new StreamStart(
            $this->generateEventId(), $pending->provider->name(), $pending->model, time()
        ))->withInvocationId($invocationId);

        if ($result->text !== '') {
            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $result->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($result->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
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

        return $tools !== [] ? (int) round(count($tools) * 1.5) : 5;
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
        return array_map(function (ToolCall $toolCall) use ($tools): ToolResult {
            $tool = $this->findTool($toolCall->name, $tools);

            if (! $tool instanceof Tool) {
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
            fn (Usage $carry, Step $step): Usage => $carry->add($step->usage),
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
                toolCalls: $steps->flatMap(fn (Step $s): array => $s->toolCalls),
                toolResults: $steps->flatMap(fn (Step $s): array => $s->toolResults),
            )->withSteps($steps);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)->withSteps($steps);
    }
}
