<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
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
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;
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
        ?Closure $onApprovalResolved = null,
    ): TextResponse {
        $steps = new Collection;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;

        if ($approval !== null) {
            [$allMessages, $originalMessageCount, $shouldContinue, $approvalResults] = $this->resumeFromApproval($approval, $messages, $tools);

            $this->commitApprovalResults($onApprovalResolved, $approvalResults);

            if (! $shouldContinue) {
                return (new TextResponse('', new Usage, new Meta($provider->name(), $model)))
                    ->withMessages(collect(array_slice($allMessages, $originalMessageCount)));
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
            $originalMessageCount = count($allMessages);
        }

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
                $options?->forStep($step),
                $timeout,
                $stepContext,
            );

            [$toolResults, $pendingApprovals] = filled($lastResult->pendingApprovals)
                ? [[], collect($lastResult->pendingApprovals)]
                : $this->continuationToolResults(
                    $lastResult->finishReason,
                    $lastResult->toolCalls,
                    $stepContext->isFinalStep,
                    $tools,
                );

            $steps->push($this->buildStep($lastResult, $toolResults));

            $allMessages[] = $this->buildAssistantMessage($lastResult);

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                return $this->buildFinalResponse($steps, $allMessages, $originalMessageCount, $lastResult)
                    ->withPendingApprovals($pendingApprovals);
            }

            if (blank($toolResults) && $lastResult->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $lastResult->continuationToken;
        }

        return $this->buildFinalResponse($steps, $allMessages, $originalMessageCount, $lastResult);
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, Decision>|null  $approval
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
        ?Closure $onApprovalResolved = null,
    ): Generator {
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;
        $sawError = false;

        if ($approval !== null) {
            [$allMessages, , $shouldContinue, $approvalResults, $rejectedToolCallIds, $failedToolCallIds] = $this->resumeFromApproval($approval, $messages, $tools);

            foreach ($approvalResults as $toolResult) {
                $rejected = in_array($toolResult->id, $rejectedToolCallIds, true);
                $failed = in_array($toolResult->id, $failedToolCallIds, true);

                yield (new ToolResultEvent(
                    strtolower((string) Str::uuid7()),
                    $toolResult,
                    ! $rejected && ! $failed,
                    $rejected || $failed ? $toolResult->result : null,
                    time(),
                    denied: $rejected,
                ))->withInvocationId($invocationId);
            }

            $this->commitApprovalResults($onApprovalResolved, $approvalResults);

            if (! $shouldContinue) {
                yield (new StreamEnd(
                    strtolower((string) Str::uuid7()),
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
                $options?->forStep($step),
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

            if ($result === null) {
                break;
            }

            $accumulatedUsage = $accumulatedUsage->add($result->usage);
            $finalReason = $result->finishReason;

            [$toolResults, $pendingApprovals] = filled($result->pendingApprovals)
                ? [[], collect($result->pendingApprovals)]
                : $this->continuationToolResults(
                    $result->finishReason,
                    $result->toolCalls,
                    $stepContext->isFinalStep,
                    $tools,
                );

            foreach ($toolResults as $toolResult) {
                yield (new ToolResultEvent(
                    strtolower((string) Str::uuid7()),
                    $toolResult,
                    ! $stepContext->isFinalStep,
                    $stepContext->isFinalStep ? $toolResult->result : null,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $allMessages[] = $this->buildAssistantMessage($result);

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                yield (new ToolApprovalRequest(
                    strtolower((string) Str::uuid7()),
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
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function continuationToolResults(FinishReason $reason, array $toolCalls, bool $isFinalStep, array $tools): array
    {
        if ($reason !== FinishReason::ToolCalls || blank($toolCalls)) {
            return [[], collect()];
        }

        return $this->approvalAwareToolResults($toolCalls, $tools, $isFinalStep);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false): array
    {
        $pendingApprovals = collect();
        $resolved = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            $approval = $tool !== null ? $this->approvalForTool($tool, $toolCall) : null;

            if ($approval !== null) {
                $pendingApprovals->push(new PendingApproval(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $approval->reason,
                ));

                continue;
            }

            if ($tool === null && ! $isFinalStep) {
                throw new NoSuchToolException($toolCall->name);
            }

            $resolved[] = [$toolCall, $tool];
        }

        $toolResults = array_map(function (array $pair) use ($isFinalStep) {
            [$toolCall, $tool] = $pair;

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $isFinalStep || $tool === null
                    ? 'The agent reached its maximum number of steps without running this tool call.'
                    : $this->executeTool($tool, $toolCall->arguments, $toolCall->id),
                $toolCall->resultId,
            );
        }, $resolved);

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Apply the approval's decisions to the pending pause, returning the updated history and resume state.
     *
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array{Message[], int, bool, array<int, ToolResult>, array<int, string>, array<int, string>}
     */
    protected function resumeFromApproval(array $approval, array $messages, array $tools): array
    {
        $messages = $this->settleAbandonedToolCalls($messages, exceptLatestAssistantTurn: true);

        $originalMessageCount = count($messages);

        [$approvalResults, $shouldContinue, $rejectedToolCallIds, $failedToolCallIds] = $this->resolveApprovalResults($approval, $messages, $tools);

        if (filled($approvalResults)) {
            [$messages, $originalMessageCount] = $this->appendApprovalResults($messages, $approvalResults, $originalMessageCount);
        }

        return [$messages, $originalMessageCount, $shouldContinue, $approvalResults, $rejectedToolCallIds, $failedToolCallIds];
    }

    /**
     * Durably record resolved approval results before the run continues, even when the run will not continue.
     *
     * @param  array<int, ToolResult>  $approvalResults
     */
    protected function commitApprovalResults(?Closure $onApprovalResolved, array $approvalResults): void
    {
        if ($onApprovalResolved !== null && filled($approvalResults)) {
            $onApprovalResolved($approvalResults);
        }
    }

    /**
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array{array<int, ToolResult>, bool, array<int, string>, array<int, string>}
     */
    protected function resolveApprovalResults(array $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedTools] = $this->validateApproval($approval, $messages, $tools);

        $toolResults = [];
        $rejectedToolCallIds = [];
        $failedToolCallIds = [];
        $shouldContinue = false;
        $bareRejection = false;

        foreach ($pendingToolCalls as $toolCall) {
            $decision = $approval[$toolCall->id]
                ?? $approval['*']
                ?? Decision::reject('This tool call was not approved.');

            if ($decision->isRejected()) {
                $rejectedToolCallIds[] = $toolCall->id;

                $toolResults[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'Tool call rejected by approver.',
                    $toolCall->resultId,
                );

                if ($decision->result !== null) {
                    $shouldContinue = true;
                } else {
                    $bareRejection = true;
                }

                continue;
            }

            $arguments = $decision->isEdited()
                ? ($decision->arguments ?? $toolCall->arguments)
                : $toolCall->arguments;

            $tool = $resolvedTools[$toolCall->id];

            if ($tool === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            try {
                $result = $this->executeTool($tool, $arguments, $toolCall->id);
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

            $shouldContinue = true;
        }

        return [$toolResults, $shouldContinue && ! $bareRejection, $rejectedToolCallIds, $failedToolCallIds];
    }

    /**
     * Get the pending approvals awaiting a decision in the given message history.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return Collection<int, PendingApproval>
     */
    public function pendingApprovals(array $messages, array $tools): Collection
    {
        [$pendingToolCalls] = $this->pendingToolCalls($messages);

        $approvals = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall) => [
            $toolCall->id => $this->approvalForTool($this->findTool($toolCall->name, $tools), $toolCall),
        ]);

        return $this->pendingApprovalsFor(
            $pendingToolCalls->filter(fn (ToolCall $toolCall) => $approvals[$toolCall->id] !== null)->values(),
            $approvals,
        );
    }

    /**
     * Validate the approval's decisions against the pending tool calls, throwing on any mismatch.
     *
     * @param  array<string, Decision>  $approval
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array{Collection<int, ToolCall>, Collection<string, ?Tool>, array<int, string>}
     */
    public function validateApproval(array $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedToolCallIds] = $this->pendingToolCalls($messages);

        $resolvedTools = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall) => [
            $toolCall->id => $this->findTool($toolCall->name, $tools),
        ]);

        $approvals = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall) => [
            $toolCall->id => $this->approvalForTool($resolvedTools[$toolCall->id], $toolCall),
        ]);

        $gated = $pendingToolCalls->filter(fn (ToolCall $toolCall) => $approvals[$toolCall->id] !== null);
        $gatedIds = $gated->pluck('id')->all();
        $decisionIds = array_values(array_diff(array_keys($approval), ['*']));

        $unknown = array_values(array_diff($decisionIds, $pendingToolCalls->pluck('id')->all()));

        $missing = array_key_exists('*', $approval)
            ? []
            : array_values(array_diff($gatedIds, $decisionIds));

        if ($unknown !== [] || $missing !== []) {
            $alreadyResolved = array_values(array_intersect($unknown, $resolvedToolCallIds));

            $message = $alreadyResolved !== []
                ? 'Approval decisions include already-resolved tool call ids.'
                : 'Approval decisions do not match the pending tool calls.';

            throw new ApprovalMismatchException($message, $this->pendingApprovalsFor($gated, $approvals));
        }

        if ($pendingToolCalls->isEmpty()) {
            throw new ApprovalMismatchException('There are no pending tool calls awaiting approval.', collect());
        }

        return [$pendingToolCalls, $resolvedTools, $gatedIds];
    }

    /**
     * Resolve the approval requirement for an already-resolved tool, or null when it is not gated.
     */
    protected function approvalForTool(?Tool $tool, ToolCall $toolCall): ?Approval
    {
        return $tool instanceof Approvable
            ? $tool->shouldRequestApproval(new Request($toolCall->arguments, $toolCall->id))
            : null;
    }

    /**
     * @param  Message[]  $messages
     * @return array{Collection<int, ToolCall>, array<int, string>}
     */
    protected function pendingToolCalls(array $messages): array
    {
        $resolved = collect($messages)
            ->whereInstanceOf(ToolResultMessage::class)
            ->flatMap(fn (ToolResultMessage $message) => $message->toolResults)
            ->pluck('id')
            ->all();

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];

            if (! $message instanceof AssistantMessage) {
                continue;
            }

            return [
                $message->toolCalls
                    ->reject(fn (ToolCall $toolCall) => in_array($toolCall->id, $resolved, true))
                    ->values(),
                $resolved,
            ];
        }

        return [collect(), $resolved];
    }

    /**
     * Append a resume's tool results, merging into the pause turn's partial results so one assistant turn keeps one answering message.
     *
     * @param  Message[]  $messages
     * @param  array<int, ToolResult>  $toolResults
     * @return array{Message[], int}
     */
    protected function appendApprovalResults(array $messages, array $toolResults, int $originalMessageCount): array
    {
        $last = end($messages);

        if ($last instanceof ToolResultMessage) {
            array_pop($messages);

            $originalMessageCount--;
            $toolResults = [...$last->toolResults->all(), ...$toolResults];
        }

        $messages[] = new ToolResultMessage(collect($toolResults));

        return [$messages, $originalMessageCount];
    }

    /**
     * Settle unresolved tool calls from abandoned pauses so the history remains replayable, optionally leaving the latest assistant turn for a resume to decide.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected function settleAbandonedToolCalls(array $messages, bool $exceptLatestAssistantTurn = false): array
    {
        $resolved = collect($messages)
            ->whereInstanceOf(ToolResultMessage::class)
            ->flatMap(fn (ToolResultMessage $message) => $message->toolResults)
            ->pluck('id')
            ->flip()
            ->all();

        $bound = count($messages);

        if ($exceptLatestAssistantTurn) {
            for ($index = $bound - 1; $index >= 0; $index--) {
                if ($messages[$index] instanceof AssistantMessage) {
                    $bound = $index;

                    break;
                }
            }
        }

        for ($index = 0; $index < $bound; $index++) {
            $message = $messages[$index];

            if (! $message instanceof AssistantMessage) {
                continue;
            }

            $dangling = $message->toolCalls->reject(
                fn (ToolCall $toolCall) => isset($resolved[$toolCall->id])
            )->values();

            if ($dangling->isEmpty()) {
                continue;
            }

            $placeholders = $dangling->map(fn (ToolCall $toolCall) => new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                'This tool call was not executed because it was not approved before the conversation continued.',
                $toolCall->resultId,
            ));

            $next = $messages[$index + 1] ?? null;

            if ($next instanceof ToolResultMessage) {
                $messages[$index + 1] = new ToolResultMessage($next->toolResults->concat($placeholders)->values());
            } else {
                array_splice($messages, $index + 1, 0, [new ToolResultMessage($placeholders->values())]);

                $bound++;
            }
        }

        return $messages;
    }

    /**
     * @param  Collection<int, ToolCall>  $toolCalls
     * @param  Collection<string, ?Approval>  $approvals
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsFor(Collection $toolCalls, Collection $approvals): Collection
    {
        return $toolCalls->map(fn (ToolCall $toolCall) => new PendingApproval(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $approvals[$toolCall->id]?->reason,
        ))->values();
    }

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
                toolResults: $newMessages
                    ->whereInstanceOf(ToolResultMessage::class)
                    ->flatMap(fn (ToolResultMessage $message) => $message->toolResults),
            )->withSteps($steps);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)->withSteps($steps);
    }
}
