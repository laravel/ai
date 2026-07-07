<?php

namespace Laravel\Ai\Contracts;

interface RemembersConversations extends Conversational
{
    /**
     * Start a new conversation for the given user.
     */
    public function forUser(object $user): static;

    /**
     * Continue an existing conversation as the given user.
     */
    public function continue(string $conversationId, object $as): static;

    /**
     * Continue the last conversation as the given user.
     */
    public function continueLastConversation(object $as): static;

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
