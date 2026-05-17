<?php

namespace Laravel\Ai\Contracts;

interface RemembersConversation
{
    /**
     * Continue an existing conversation as the given user.
     */
    public function continue(string $conversationId, object $as): static;

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string;

    /**
     * Determine if the conversation has a participant and is thus being remembered.
     */
    public function hasConversationParticipant(): bool;

    /**
     * Get the user having the current conversation.
     */
    public function conversationParticipant(): ?object;
}
