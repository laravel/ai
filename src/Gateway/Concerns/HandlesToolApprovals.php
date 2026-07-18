<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

trait HandlesToolApprovals
{
    /**
     * Get the unresolved tool calls in the latest assistant turn, plus the ids already answered.
     *
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
     * @return array{Message[], Message[]}
     */
    protected function appendApprovalResults(array $messages, array $toolResults): array
    {
        $last = end($messages);

        if ($last instanceof ToolResultMessage) {
            array_pop($messages);

            $toolResults = [...$last->toolResults->all(), ...$toolResults];
        }

        $answer = new ToolResultMessage(collect($toolResults));

        $messages[] = $answer;

        return [$messages, [$answer]];
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

        $bound = $exceptLatestAssistantTurn ? $this->latestAssistantTurnIndex($messages) : count($messages);

        $output = [];

        for ($index = 0, $count = count($messages); $index < $count; $index++) {
            $message = $messages[$index];

            if ($index >= $bound || ! $message instanceof AssistantMessage) {
                $output[] = $message;

                continue;
            }

            $dangling = $message->toolCalls->reject(
                fn (ToolCall $toolCall) => isset($resolved[$toolCall->id])
            )->values();

            $output[] = $message;

            if ($dangling->isEmpty()) {
                continue;
            }

            $placeholders = $dangling->map(fn (ToolCall $toolCall) => new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                'This tool call was not executed because it was not approved before the conversation continued.',
                $toolCall->resultId,
                denied: true,
            ));

            $next = $messages[$index + 1] ?? null;

            if ($next instanceof ToolResultMessage) {
                $output[] = new ToolResultMessage($next->toolResults->concat($placeholders)->values());

                $index++;
            } else {
                $output[] = new ToolResultMessage($placeholders->values());
            }
        }

        return $output;
    }

    /**
     * Find the index of the latest assistant message, or the message count when there is none.
     *
     * @param  Message[]  $messages
     */
    protected function latestAssistantTurnIndex(array $messages): int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ($messages[$index] instanceof AssistantMessage) {
                return $index;
            }
        }

        return count($messages);
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
}
