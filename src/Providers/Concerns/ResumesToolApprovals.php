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
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

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
        return $prompt->hasApprovalDecisions() && ! Ai::hasFakeGatewayFor($prompt->agent::class);
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
     * Ensure the resumption's results could be recorded before any approved tool runs.
     *
     * @param  Collection<int, ToolCall>  $pendingToolCalls
     *
     * @throws ApprovalMismatchException when the resuming participant does not own the paused turn
     */
    protected function ensureApprovalResumptionIsRecordable(AgentPrompt $prompt, Collection $pendingToolCalls): void
    {
        $context = $this->storeApprovalContextFor($prompt);

        if ($context === null) {
            return;
        }

        [$store, $conversationId, $participantType, $participantId] = $context;

        if (! $store->hasPausedTurn($conversationId, $participantType, $participantId, $pendingToolCalls->pluck('id')->all())) {
            throw new ApprovalMismatchException('The approval decisions do not match a paused conversation turn for this participant.', collect());
        }
    }

    /**
     * Get a callback that durably records resolved approval results before the run continues, if the store supports it.
     */
    protected function storeApprovalResultRecorderFor(AgentPrompt $prompt): ?Closure
    {
        $context = $this->storeApprovalContextFor($prompt);

        if ($context === null) {
            return null;
        }

        [$store, $conversationId, $participantType, $participantId] = $context;

        return fn (array $toolResults) => $store->storeApprovalResults($conversationId, $participantType, $participantId, $toolResults);
    }

    /**
     * Resolve the store and the identity a durable resumption records under, or null when the agent does not remember conversations.
     *
     * @return array{ConversationStore, string, ?string, string|int|null}|null
     */
    protected function storeApprovalContextFor(AgentPrompt $prompt): ?array
    {
        $agent = $prompt->agent;

        if (! in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
            return null;
        }

        /** @var Agent&RemembersConversationsContract $agent */
        if ($agent->currentConversation() === null) {
            return null;
        }

        $participant = $agent->conversationParticipant();

        return [
            app(ConversationStore::class),
            $agent->currentConversation(),
            $participant === null ? null : Conversation::participantType($participant),
            $participant === null ? null : Conversation::participantKey($participant),
        ];
    }
}
