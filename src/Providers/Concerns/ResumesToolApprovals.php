<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\ProviderTool;

trait ResumesToolApprovals
{
    /**
     * Get the tool approval to resume with, unless a faked agent has no paused approval to resume.
     *
     * @param  array<int, Message>  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @return array<string, Decision>|null
     */
    protected function resumableApprovalFor(AgentPrompt $prompt, array $messages, array $tools): ?array
    {
        return $this->resumesApproval($prompt, $messages, $tools) ? $prompt->approvalDecisions->all() : null;
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
     * Determine whether the prompt is a resume that runs the approved tools.
     *
     * @param  array<int, Message>  $messages
     * @param  array<Tool|ProviderTool>  $tools
     */
    protected function resumesApproval(AgentPrompt $prompt, array $messages, array $tools): bool
    {
        if (! $prompt->hasApprovalDecisions()) {
            return false;
        }

        if (! Ai::hasFakeGatewayFor($prompt->agent::class)) {
            return true;
        }

        // A faked agent resumes like the real gateway once it actually paused for approval, but decisions sent to a fake with nothing paused still just take its next response...
        return $this->textGenerationLoop()->pausedForApproval($messages, $tools);
    }

    /**
     * Get a callback that captures a resume's resolved approval results, also durably recording them when the store supports it.
     *
     * @param  array<string, Decision>|null  $approval
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?array $approval, ?Collection &$resolvedApprovalResults): ?Closure
    {
        if ($approval === null) {
            return null;
        }

        $storeRecorder = $this->storeApprovalResultRecorderFor($prompt);

        return function (array $toolResults) use ($storeRecorder, &$resolvedApprovalResults): void {
            $resolvedApprovalResults = collect($toolResults);

            if ($storeRecorder !== null) {
                $storeRecorder($toolResults);
            }
        };
    }

    /**
     * Get a callback that durably records resolved approval results before the run continues, if the store supports it.
     */
    protected function storeApprovalResultRecorderFor(AgentPrompt $prompt): ?Closure
    {
        $agent = $prompt->agent;

        if (! in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
            return null;
        }

        /** @var Agent&RemembersConversationsContract $agent */
        $conversationId = $agent->currentConversation();

        if ($conversationId === null) {
            return null;
        }

        $store = app(ConversationStore::class);

        return fn (array $toolResults) => $store->storeApprovalResults($conversationId, $toolResults);
    }
}
