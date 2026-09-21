<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;

trait ResumesToolApprovals
{
    /**
     * Get the tool approval to resume with, unless the agent's gateway is faked.
     *
     * @return array<string, Decision>|null
     */
    protected function resumableApprovalFor(AgentPrompt $prompt): ?array
    {
        return $prompt->resumesAgainstRealGateway() ? $prompt->approvalDecisions->all() : null;
    }

    /**
     * Replace another provider's raw paused-turn replay state with its generic mapping, since raw blocks are only valid verbatim on the provider that produced them.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    protected function withoutForeignReplayBlocks(array $messages): array
    {
        return array_map(function (Message $message): Message {
            if ($message instanceof AssistantMessage
                && filled($message->replayBlocks)
                && $message->replayBlocksProvider !== null
                && $message->replayBlocksProvider !== $this->name()) {
                return new AssistantMessage($message->content, $message->toolCalls);
            }

            return $message;
        }, $messages);
    }

    /**
     * Get a callback that captures a resume's resolved approval results for the ToolApprovalResolved event.
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?Collection &$resolvedApprovalResults): ?Closure
    {
        if (! $prompt->resumesAgainstRealGateway()) {
            return null;
        }

        return function (array $toolResults) use (&$resolvedApprovalResults): void {
            $resolvedApprovalResults = collect($toolResults);
        };
    }
}
