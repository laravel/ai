<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Gateway\Concerns\HandlesToolApprovals;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\MeasuresDuration;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Throwable;

use function Laravel\Ai\pipeline;

class TextGenerationLoop
{
    use HandlesToolApprovals, InvokesTools, MeasuresDuration;

    private bool $repairsToolCalls = false;

    /**
     * The default ceiling for the derived step budget when it is not set explicitly.
     */
    protected const DEFAULT_MAX_STEPS = 25;

    /**
     * Create a new text generation loop instance.
     */
    public function __construct(protected StepTextGateway $gateway) {}

    /**
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, Decision>|null  $approval
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
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?RunContext $context = null,
    ): TextResponse {
        $this->ensureToolSearchIsApplicable($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = new Collection;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new Usage;
        $lastResult = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, context: $context);

            $allMessages = $resumption->messages;
            $newMessages = $resumption->newMessages;

            if ($recordApprovalResults !== null) {
                $recordApprovalResults($resumption->results);
            }

            if (! $resumption->shouldContinue) {
                return (new TextResponse('', new Usage, new Meta($provider->name(), $model)))
                    ->withMessages(collect($newMessages));
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
            $newMessages = [];
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->all(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            $prepared = $pending;
            $stepContext = null;
            $startedAt = null;

            try {
                $lastResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($provider, $previous, $context, $allMessages, $continuationToken, &$prepared, &$stepContext, &$startedAt): StepResult {
                    $prepared = $step;
                    $stepContext = $this->stepContextFor($step, $previous, $allMessages, $continuationToken);

                    $context?->startingStep($stepContext, $step->messages, $step->options, $step->model);

                    $startedAt = hrtime(true);

                    return new StepResult($this->gateway->generateTextStep(
                        $provider,
                        $step->model,
                        $step->instructions,
                        $step->messages,
                        $step->tools,
                        $step->schema,
                        $step->options,
                        $step->timeout,
                        $stepContext,
                    ));
                })->response();
            } catch (Throwable $exception) {
                if ($stepContext !== null) {
                    $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt), $prepared->model);
                }

                throw $exception;
            }

            if ($stepContext !== null) {
                $context?->stepCompleted($stepContext, $lastResult, $this->elapsedMilliseconds($startedAt), $prepared->model);
            }

            $accumulatedUsage = $accumulatedUsage->add($lastResult->usage);

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($lastResult, $prepared->isFinalStep, $prepared->tools, $prepared->options, $context);

            $steps->push($this->buildStep($lastResult, $toolResults));

            $assistantMessage = $this->buildAssistantMessage($lastResult);
            $allMessages[] = $assistantMessage;
            $newMessages[] = $assistantMessage;

            if (filled($toolResults)) {
                $toolResultMessage = new ToolResultMessage(collect($toolResults));
                $allMessages[] = $toolResultMessage;
                $newMessages[] = $toolResultMessage;
            }

            if ($pendingApprovals->isNotEmpty()) {
                return $this->buildFinalResponse($steps, $newMessages, $lastResult)
                    ->withPendingApprovals($pendingApprovals);
            }

            if (blank($toolResults) && $lastResult->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $lastResult->continuationToken;
            $previous = $prepared;
        }

        return $this->buildFinalResponse($steps, $newMessages, $lastResult);
    }

    /**
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, Decision>|null  $approval
     * @param  array{Collection<int, ToolCall>, Collection<string, ?Tool>}|null  $validatedApproval  the caller's own eager validateApproval() result, reused instead of re-validating
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
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?array $validatedApproval = null,
        ?RunContext $context = null,
    ): Generator {
        $this->ensureToolSearchIsApplicable($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = new Collection;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, $validatedApproval, $context);

            $allMessages = $resumption->messages;

            if ($recordApprovalResults !== null) {
                $recordApprovalResults($resumption->results);
            }

            foreach ($resumption->results as $toolResult) {
                $failed = in_array($toolResult->id, $resumption->failedToolCallIds, true);

                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    ! $toolResult->denied && ! $failed,
                    $toolResult->denied || $failed ? $toolResult->result : null,
                    time(),
                    denied: $toolResult->denied,
                ))->withInvocationId($invocationId);
            }

            if (! $resumption->shouldContinue) {
                yield (new StreamEnd(
                    $this->generateEventId(),
                    FinishReason::Stop->value,
                    $accumulatedUsage,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->all(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            $prepared = $pending;
            $stepContext = null;
            $startedAt = null;
            $lastError = null;

            try {
                $stepResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($invocationId, $provider, $previous, $context, $allMessages, $continuationToken, &$prepared, &$stepContext, &$startedAt): StepResult {
                    $prepared = $step;
                    $stepContext = $this->stepContextFor($step, $previous, $allMessages, $continuationToken);

                    $context?->startingStep($stepContext, $step->messages, $step->options, $step->model);

                    $startedAt = hrtime(true);

                    return new StepResult($this->gateway->generateStreamStep(
                        $invocationId,
                        $provider,
                        $step->model,
                        $step->instructions,
                        $step->messages,
                        $step->tools,
                        $step->schema,
                        $step->options,
                        $step->timeout,
                        $stepContext,
                    ));
                });

                foreach ($stepResult as $event) {
                    yield $event;

                    if ($event instanceof Error) {
                        $lastError = $event;
                    }
                }

                $result = $stepResult->response();

                if (! $stepResult->streamed() && $result instanceof StepResponse) {
                    yield from $this->eventsFor($invocationId, $provider, $prepared->model, $result);
                }
            } catch (Throwable $exception) {
                if ($stepContext !== null) {
                    $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt), $prepared->model);
                }

                throw $exception;
            }

            // A provider may report an error in the stream itself rather than throwing, which still ends the step. The error event travels on the exception so its type and metadata are not lost...
            if (! $result instanceof StepResponse) {
                $exception = new StreamErrorException($lastError);

                if ($stepContext !== null) {
                    $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt), $prepared->model);
                }

                throw $exception;
            }

            if ($stepContext !== null) {
                $context?->stepCompleted($stepContext, $result, $this->elapsedMilliseconds($startedAt), $prepared->model);
            }

            $accumulatedUsage = $accumulatedUsage->add($result->usage);
            $finalReason = $result->finishReason;

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($result, $prepared->isFinalStep, $prepared->tools, $prepared->options, $context);

            $steps->push($this->buildStep($result, $toolResults));

            foreach ($toolResults as $toolResult) {
                $successful = ! $prepared->isFinalStep && $this->findTool($toolResult->name, $prepared->tools) instanceof Tool;

                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $successful,
                    $successful ? null : $toolResult->result,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $allMessages[] = $this->buildAssistantMessage($result);

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                yield (new ToolApprovalRequest(
                    $this->generateEventId(),
                    $pendingApprovals,
                    time(),
                    $result->providerContentBlocks,
                ))->withInvocationId($invocationId);

                break;
            }

            if (blank($toolResults) && $result->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $result->continuationToken;
            $previous = $prepared;
        }

        // A step that never produced a response has already thrown, so the loop only reaches here having set a reason...
        yield (new StreamEnd(
            $this->generateEventId(),
            ($finalReason ?? FinishReason::Stop)->value,
            $accumulatedUsage,
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * The middleware wrapping each step, as declared by the agent being run.
     *
     * @return array<int, mixed>
     */
    protected function middlewareFor(?TextGenerationOptions $options): array
    {
        return $options?->agent instanceof HasMiddleware ? $options->agent->middleware() : [];
    }

    /**
     * @param  array<int, mixed>  $middleware
     * @param  Closure(PendingStep): StepResult  $run
     */
    protected function runStep(PendingStep $step, array $middleware, Closure $run): StepResult
    {
        $result = $middleware === [] ? $run($step) : pipeline()->send($step)->through($middleware)->then($run);

        return match (true) {
            $result instanceof StepResult => $result,
            $result instanceof StepResponse => new StepResult($result),
            default => throw new LogicException('Agent middleware must return the next step result or a StepResponse.'),
        };
    }

    /**
     * A provider-side continuation carries the previous step's history, model and instructions, so a step that changes any of them replays in full instead.
     *
     * @param  Message[]  $history
     */
    protected function stepContextFor(PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken): StepContext
    {
        $unchanged = $step->messages === $history && $step->model === $previous?->model && $step->instructions === $previous?->instructions;

        return new StepContext(
            stepNumber: $step->number,
            isFinalStep: $step->isFinalStep,
            continuationToken: $unchanged ? $continuationToken : null,
        );
    }

    /**
     * The stream events describing a step response that was produced without streaming.
     *
     * @return Generator<int, StreamEvent>
     */
    protected function eventsFor(string $invocationId, TextProvider $provider, string $model, StepResponse $response): Generator
    {
        yield (new StreamStart($this->generateEventId(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (filled($response->text)) {
            $messageId = $this->generateEventId();

            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $response->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
        }
    }

    /**
     * Resolve the step budget: explicit `maxSteps`, else 1.5x tools capped at the ceiling, else 5.
     *
     * @param  array<Tool|ProviderTool>  $tools
     */
    protected function resolveMaxSteps(?TextGenerationOptions $options, array $tools): int
    {
        if ($options?->maxSteps !== null) {
            return max(1, $options->maxSteps);
        }

        $count = ToolSearch::budget($tools);
        $maxSteps = $count > 0 ? min((int) round($count * 1.5), self::DEFAULT_MAX_STEPS) : 5;

        return $maxSteps + (int) RepairToolCalls::isAppliedTo($options?->agent);
    }

    /**
     * Resolve tool results using the generation options for the current step.
     *
     * @param  array<Tool|ProviderTool>  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    private function stepToolResultsWithOptions(StepResponse $result, bool $isFinalStep, array $tools, ?TextGenerationOptions $options, ?RunContext $context = null): array
    {
        $repairsToolCalls = $this->repairsToolCalls;

        $this->repairsToolCalls = RepairToolCalls::isAppliedTo($options?->agent);

        try {
            return $this->stepToolResults($result, $isFinalStep, $tools, $context);
        } finally {
            $this->repairsToolCalls = $repairsToolCalls;
        }
    }

    /**
     * Tool results to continue the loop with, plus any pending approvals that pause it.
     *
     * @param  array<Tool|ProviderTool>  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        if (filled($result->pendingApprovals)) {
            return [[], collect($result->pendingApprovals)];
        }

        if ($result->finishReason !== FinishReason::ToolCalls || blank($result->toolCalls)) {
            return [[], collect()];
        }

        return $this->approvalAwareToolResults($result->toolCalls, $tools, $isFinalStep, $context);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<Tool|ProviderTool>  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        $pendingApprovals = collect();
        $resolved = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            $approval = $tool instanceof Tool ? $this->approvalForTool($tool, $toolCall) : null;

            if ($approval instanceof Approval) {
                $pendingApprovals->push(new PendingApproval(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $approval->reason,
                ));

                continue;
            }

            if (! $tool instanceof Tool && ! $isFinalStep && ! $this->repairsToolCalls) {
                throw new NoSuchToolException($toolCall->name);
            }

            $resolved[] = [$toolCall, $tool];
        }

        $toolResults = array_map(function (array $pair) use ($tools, $isFinalStep, $context) {
            [$toolCall, $tool] = $pair;

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                match (true) {
                    ! $tool instanceof Tool && $this->repairsToolCalls => "Tool '{$toolCall->name}' does not exist. Available tools: {$this->availableToolNames($tools)}.",
                    $isFinalStep => 'The agent reached its maximum number of steps without running this tool call.',
                    default => $this->executeTool($tool, $toolCall->arguments, $toolCall->id, $context),
                },
                $toolCall->resultId,
            );
        }, $resolved);

        return [$toolResults, $pendingApprovals];
    }

    /**
     * The names of the locally executable tools, as advertised back to a model that called an unknown one.
     *
     * @param  array<Tool|ProviderTool>  $tools
     */
    protected function availableToolNames(array $tools): string
    {
        return implode(', ', array_map(
            ToolNameResolver::resolve(...),
            array_filter($tools, fn (mixed $tool): bool => $tool instanceof Tool),
        )) ?: 'none';
    }

    /**
     * Apply the approval's decisions to the pending pause, returning the updated history and resume state.
     *
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array{Collection<int, ToolCall>, Collection<string, ?Tool>}|null  $validatedApproval  a caller's own eager validateApproval() result, reused instead of re-validating
     */
    protected function resumeFromApproval(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): ApprovalResumption
    {
        $messages = $this->settleAbandonedToolCalls($messages, exceptLatestAssistantTurn: true);

        [$approvalResults, $shouldContinue, $failedToolCallIds] = $this->resolveApprovalResults($approval, $messages, $tools, $validatedApproval, $context);

        $newMessages = [];

        if (filled($approvalResults)) {
            [$messages, $newMessages] = $this->appendApprovalResults($messages, $approvalResults);
        }

        return new ApprovalResumption(
            messages: $messages,
            newMessages: $newMessages,
            results: $approvalResults,
            failedToolCallIds: $failedToolCallIds,
            shouldContinue: $shouldContinue,
        );
    }

    /**
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array{Collection<int, ToolCall>, Collection<string, ?Tool>}|null  $validatedApproval  a caller's own eager validateApproval() result, reused instead of re-validating
     * @return array{array<int, ToolResult>, bool, array<int, string>}
     */
    protected function resolveApprovalResults(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): array
    {
        [$pendingToolCalls, $resolvedTools] = $validatedApproval ?? $this->validateApproval($approval, $messages, $tools);

        $toolResults = [];
        $failedToolCallIds = [];
        $hasBareRejection = false;

        foreach ($pendingToolCalls as $toolCall) {
            $decision = $approval[$toolCall->id]
                ?? $approval['*']
                ?? Decision::reject('The user rejected this tool call.');

            if ($decision->isRejected()) {
                $hasBareRejection = $hasBareRejection || $decision->result === null;

                $toolResults[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'The user rejected this tool call.',
                    $toolCall->resultId,
                    denied: true,
                );

                continue;
            }

            $arguments = $decision->arguments ?? $toolCall->arguments;
            $tool = $resolvedTools[$toolCall->id];

            if (! $tool instanceof Tool) {
                throw new NoSuchToolException($toolCall->name);
            }

            try {
                $result = $this->executeTool($tool, $arguments, $toolCall->id, $context);
            } catch (Throwable $exception) {
                $failedToolCallIds[] = $toolCall->id;
                $result = 'The tool call failed: '.$exception->getMessage();
            }

            $toolResults[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return [$toolResults, ! $hasBareRejection, $failedToolCallIds];
    }

    /**
     * Validate the approval's decisions against the pending tool calls, throwing on any mismatch.
     *
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @return array{Collection<int, ToolCall>, Collection<string, ?Tool>}
     */
    public function validateApproval(array $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedToolCallIds] = $this->pendingToolCalls($messages);

        $pendingIds = $pendingToolCalls->pluck('id');

        $decisionIds = collect(array_keys($approval))->reject(
            fn ($id) => $id === '*',
        );

        $resolvedTools = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall) => [
            $toolCall->id => $this->findTool($toolCall->name, $tools),
        ]);

        $approvals = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall) => [
            $toolCall->id => $this->approvalForTool($resolvedTools[$toolCall->id], $toolCall),
        ]);

        $gated = $pendingToolCalls->filter(
            fn (ToolCall $toolCall) => $approvals[$toolCall->id] instanceof Approval
        );

        $unknown = $decisionIds->diff($pendingIds);

        $missing = array_key_exists('*', $approval)
            ? collect()
            : $gated->pluck('id')->diff($decisionIds);

        if ($unknown->isNotEmpty() || $missing->isNotEmpty()) {
            $message = $unknown->intersect($resolvedToolCallIds)->isNotEmpty()
                ? 'Approval decisions include already-resolved tool call ids.'
                : 'Approval decisions do not match the pending tool calls.';

            throw new ApprovalMismatchException($message, $this->pendingApprovalsFor($gated, $approvals));
        }

        if ($pendingToolCalls->isEmpty()) {
            throw new ApprovalMismatchException('There are no tool calls pending approval.', collect());
        }

        return [$pendingToolCalls, $resolvedTools];
    }

    /**
     * Resolve the approval requirement for a tool call, or null when it is not gated.
     */
    protected function approvalForTool(?Tool $tool, ToolCall $toolCall): ?Approval
    {
        return $tool instanceof Approvable
            ? $tool->shouldRequestApproval(new Request($toolCall->arguments, $toolCall->id))
            : null;
    }

    /**
     * Generate a unique stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    /**
     * Build an assistant message from the given step response.
     */
    protected function buildAssistantMessage(StepResponse $result): AssistantMessage
    {
        return new AssistantMessage(
            $result->text,
            collect($result->toolCalls),
            $result->providerContentBlocks,
        );
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function buildStep(StepResponse $result, array $toolResults = []): Step
    {
        return (new Step(
            $result->text,
            $result->toolCalls,
            $toolResults,
            $result->finishReason,
            $result->usage,
            $result->meta,
        ))->withRawResponse($result->raw);
    }

    /**
     * Build the final text response from all generated steps.
     */
    protected function buildFinalResponse(
        Collection $steps,
        array $newMessages,
        ?StepResponse $lastResult,
    ): TextResponse {
        $finalStep = $steps->last();

        $totalUsage = $steps->reduce(
            fn (Usage $carry, Step $step): Usage => $carry->add($step->usage),
            new Usage,
        );

        $newMessages = collect($newMessages)->values();

        if ($lastResult?->structured !== null) {
            return (new StructuredTextResponse(
                $lastResult->structured,
                $finalStep->text,
                $totalUsage,
                $finalStep->meta,
            ))->withMessages($newMessages)->withToolCallsAndResults(
                toolCalls: $steps->flatMap(fn (Step $s): array => $s->toolCalls),
                toolResults: $newMessages
                    ->whereInstanceOf(ToolResultMessage::class)
                    ->flatMap(fn (ToolResultMessage $message): Collection => $message->toolResults),
            )->withSteps($steps)->withRawResponse($lastResult->raw);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)->withSteps($steps)->withRawResponse($lastResult?->raw);
    }

    /**
     * Ensure hosted tool search is only used with a supporting provider and a single wrapper.
     *
     * @param  Tool[]  $tools
     */
    protected function ensureToolSearchIsApplicable(TextProvider $provider, array $tools): void
    {
        $wrappers = array_filter($tools, fn ($tool): bool => $tool instanceof ToolSearch);

        if ($wrappers === []) {
            return;
        }

        if (! $provider instanceof SupportsToolSearch) {
            throw new LogicException("Provider [{$provider->name()}] does not support tool search.");
        }

        if (count($wrappers) > 1) {
            throw new LogicException('Only a single tool search wrapper may be registered per request.');
        }
    }
}
