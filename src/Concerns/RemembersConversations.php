<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Contracts\ConversationStore;

trait RemembersConversations
{
    protected ?string $conversationId = null;

    protected ?object $conversationUser = null;

    /**
     * Start a new conversation for the given user.
     */
    public function forUser($user): static
    {
        $this->conversationUser = $user;

        return $this;
    }

    /**
     * Continue an existing conversation as the given user.
     */
    public function continue(string $conversationId, object $as): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $as;

        return $this;
    }

    /**
     * Continue an existing conversation as the given user.
     */
    public function continueLastConversation(object $as): static
    {
        $this->conversationUser = $as;

        $store = resolve(ConversationStore::class);

        // Propagate tenant context to store if agent has HasTenantContext trait & this.tenantId is set in the agent by calling forTenant() method as part of the agent's chain of methods to create the prompt
        if (method_exists($this, 'hasTenantContext') && $this->hasTenantContext()) {
            $store->forTenant($this->currentTenant());
        }

        $this->conversationId = $store->latestConversationId($as->id);

        return $this;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        $store = resolve(ConversationStore::class);

        // Propagate tenant context to store if agent has HasTenantContext trait
        if (method_exists($this, 'hasTenantContext') && $this->hasTenantContext()) {
            $store->forTenant($this->currentTenant());
        }

        return $store->getLatestConversationMessages(
            $this->conversationId,
            $this->maxConversationMessages()
        )->all();
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return 100;
    }

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Determine if the conversation has a participant and is thus being remembered.
     */
    public function hasConversationParticipant(): bool
    {
        return $this->conversationUser !== null;
    }

    /**
     * Get the user having the current conversation.
     */
    public function conversationParticipant(): ?object
    {
        return $this->conversationUser;
    }
}
