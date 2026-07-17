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
        return $this->resumesAgainstRealGateway($prompt) ? $prompt->approvalDecisions->all() : null;
    }

    /**
     * Replace another provider's raw paused-turn replay state with its generic mapping, since raw blocks are only valid verbatim on the provider that produced them.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    protected function withoutForeignProviderContentBlocks(array $messages): array
    {
        return array_map(function (Message $message): Message {
            if ($message instanceof AssistantMessage
                && filled($message->providerContentBlocks)
                && $message->providerContentBlocksProvider !== null
                && $message->providerContentBlocksProvider !== $this->name()) {
                return new AssistantMessage($message->content, $message->toolCalls);
            }

            return $message;
        }, $messages);
    }

    /**
     * Determine whether the prompt is a resume that runs tools against the real (non-faked) gateway.
     */
    protected function resumesAgainstRealGateway(AgentPrompt $prompt): bool
    {
        return $prompt->isApprovalContinuation() && ! Ai::hasFakeGatewayFor($prompt->agent::class);
    }

    /**
     * Get a callback that captures a resume's resolved approval results, also durably recording them when the store supports it.
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?Collection &$resolvedApprovalResults): ?Closure
    {
        if (! $this->resumesAgainstRealGateway($prompt)) {
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
        if ($agent->currentConversation() === null) {
            return null;
        }

        $store = app(ConversationStore::class);

        $conversationId = $agent->currentConversation();
        $participantId = $agent->conversationParticipant()?->id;

        return fn (array $toolResults) => $store->storeApprovalResults($conversationId, $participantId, $toolResults);
    }
}
