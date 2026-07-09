<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Approvals\ToolApproval;
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
        ?ToolApproval $approval = null,
    ): TextResponse {
        $steps = new Collection;
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;

        if ($approval !== null) {
            [$approvalResults, $shouldContinue] = $this->resolveApprovalResults($approval, $messages, $tools);

            $allMessages[] = new ToolResultMessage(collect($approvalResults));

            if (! $shouldContinue) {
                return (new TextResponse('', new Usage, new Meta($provider->name(), $model)))
                    ->withMessages(collect(array_slice($allMessages, count($messages))));
            }
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
                $options,
                $timeout,
                $stepContext,
            );

            // A gateway may surface a pause directly (e.g. a faked paused response).
            if (filled($lastResult->pendingApprovals)) {
                $steps->push($this->buildStep($lastResult));

                $allMessages[] = new AssistantMessage(
                    $lastResult->text,
                    collect($lastResult->toolCalls),
                    $lastResult->providerContentBlocks,
                );

                return $this->buildFinalResponse($steps, $allMessages, count($messages), $lastResult)
                    ->withPendingApprovals(collect($lastResult->pendingApprovals));
            }

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

            [$toolResults, $pendingApprovals] = $this->continuationToolResults(
                $lastResult->finishReason,
                $lastResult->toolCalls,
                $stepContext->isFinalStep,
                $tools
            );

            $shouldContinue = filled($toolResults) && $pendingApprovals->isEmpty();

            $steps->push($this->buildStep($lastResult, $toolResults));

            $allMessages[] = new AssistantMessage(
                $lastResult->text,
                collect($lastResult->toolCalls),
                $lastResult->providerContentBlocks,
            );

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                return $this->buildFinalResponse($steps, $allMessages, count($messages), $lastResult)
                    ->withPendingApprovals($pendingApprovals);
            }

            if (! $shouldContinue) {
                break;
            }

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
        ?ToolApproval $approval = null,
    ): Generator {
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;
        $sawError = false;

        if ($approval !== null) {
            [$approvalResults, $shouldContinue] = $this->resolveApprovalResults($approval, $messages, $tools);

            foreach ($approvalResults as $toolResult) {
                $rejected = ($approval->decisions[$toolResult->id] ?? null)?->action === 'reject';

                yield (new ToolResultEvent(
                    strtolower((string) Str::uuid7()),
                    $toolResult,
                    ! $rejected,
                    $rejected ? $toolResult->result : null,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $allMessages[] = new ToolResultMessage(collect($approvalResults));

            if (! $shouldContinue) {
                yield (new StreamEnd(
                    strtolower((string) Str::uuid7()),
                    FinishReason::Stop->value,
                    $accumulatedUsage,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }
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

            [$toolResults, $pendingApprovals] = $result !== null
                ? $this->continuationToolResults($result->finishReason, $result->toolCalls, $stepContext->isFinalStep, $tools)
                : [[], collect()];

            $shouldContinue = filled($toolResults) && $pendingApprovals->isEmpty();

            // Yield executed tool results even when a sibling call still needs approval...
            if (filled($toolResults)) {
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

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                yield (new ToolApprovalRequest(
                    strtolower((string) Str::uuid7()),
                    $pendingApprovals,
                    time(),
                ))->withInvocationId($invocationId);

                break;
            }

            if (! $shouldContinue) {
                break;
            }

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
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function continuationToolResults(FinishReason $reason, array $toolCalls, bool $isFinalStep, array $tools): array
    {
        if ($reason !== FinishReason::ToolCalls || blank($toolCalls)) {
            return [[], collect()];
        }

        // Budget exhausted: don't execute, but record a result per call so a trailing tool_use with no matching tool_result doesn't 400 the provider on the next turn...
        if ($isFinalStep) {
            return [$this->exhaustedToolResults($toolCalls), collect()];
        }

        return $this->approvalAwareToolResults($toolCalls, $tools);
    }

    /**
     * Build placeholder results for tool calls that could not run because the step budget was exhausted.
     *
     * @param  ToolCall[]  $toolCalls
     * @return array<int, ToolResult>
     */
    protected function exhaustedToolResults(array $toolCalls): array
    {
        return array_map(fn (ToolCall $toolCall) => new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            'The agent reached its maximum number of steps without running this tool call.',
            $toolCall->resultId,
        ), $toolCalls);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools): array
    {
        $pendingApprovals = collect();

        foreach ($toolCalls as $toolCall) {
            if ($this->findTool($toolCall->name, $tools) === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            $approval = $this->approvalFor($toolCall, $tools);

            if ($approval?->isRequired()) {
                $pendingApprovals->push(new PendingApproval(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $approval->reason,
                ));
            }
        }

        // Defer the whole step if any call needs approval...
        if ($pendingApprovals->isNotEmpty()) {
            return [[], $pendingApprovals];
        }

        $toolResults = array_map(fn (ToolCall $toolCall) => new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $this->executeTool($this->findTool($toolCall->name, $tools), $toolCall->arguments),
            $toolCall->resultId,
        ), $toolCalls);

        return [$toolResults, collect()];
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array{array<int, ToolResult>, bool}
     */
    protected function resolveApprovalResults(ToolApproval $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedToolCallIds] = $this->pendingToolCalls($messages);

        // Only gated calls need a decision...
        $gated = $pendingToolCalls->filter(fn (ToolCall $toolCall) => $this->toolRequiresApproval($toolCall, $tools));
        $gatedIds = $gated->pluck('id')->all();
        $decisionIds = array_keys($approval->decisions);

        $unknown = array_values(array_diff($decisionIds, $gatedIds));
        $missing = array_values(array_diff($gatedIds, $decisionIds));

        if ($unknown !== [] || $missing !== []) {
            $alreadyResolved = array_values(array_intersect($unknown, $resolvedToolCallIds));

            $message = $alreadyResolved !== []
                ? 'Approval decisions include already-resolved tool call ids.'
                : 'Approval decisions do not match the pending tool calls.';

            throw new ApprovalMismatchException($message, $this->pendingApprovalsFor($gated, $tools));
        }

        $toolResults = [];
        $shouldContinue = false;

        foreach ($pendingToolCalls as $toolCall) {
            $decision = $approval->decisions[$toolCall->id] ?? Approval::approve();

            if ($decision->action === 'reject') {
                $toolResults[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'Tool call rejected by approver.',
                    $toolCall->resultId,
                );

                // Continue only if the rejection carries a message for the model...
                if ($decision->result !== null) {
                    $shouldContinue = true;
                }

                continue;
            }

            $arguments = $decision->action === 'edit'
                ? $decision->arguments
                : $toolCall->arguments;

            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            $toolResults[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $arguments,
                $this->executeTool($tool, $arguments),
                $toolCall->resultId,
            );

            $shouldContinue = true;
        }

        return [$toolResults, $shouldContinue];
    }

    /**
     * Resolve the approval requirement for a tool call, or null when the tool is not gated.
     *
     * @param  Tool[]  $tools
     */
    protected function approvalFor(ToolCall $toolCall, array $tools): ?Approval
    {
        $tool = $this->findTool($toolCall->name, $tools);

        return $tool instanceof Approvable
            ? $tool->shouldRequestApproval(new Request($toolCall->arguments))
            : null;
    }

    /**
     * Determine whether a pending tool call requires human approval.
     *
     * @param  Tool[]  $tools
     */
    protected function toolRequiresApproval(ToolCall $toolCall, array $tools): bool
    {
        return $this->approvalFor($toolCall, $tools)?->isRequired() === true;
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

            if (! $message instanceof AssistantMessage || $message->toolCalls->isEmpty()) {
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
     * @param  Collection<int, ToolCall>  $toolCalls
     * @param  Tool[]  $tools
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsFor(Collection $toolCalls, array $tools): Collection
    {
        return $toolCalls->map(fn (ToolCall $toolCall) => new PendingApproval(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            ($this->approvalFor($toolCall, $tools) ?? Approval::required())->reason,
        ))->values();
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
