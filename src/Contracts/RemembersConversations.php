<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Storage\RecordedTurn;

interface RemembersConversations extends Conversational
{
    /**
     * Start a new conversation for the given participant.
     */
    public function forParticipant(object $participant): static;

    /**
     * Start a new conversation for the given user.
     */
    public function forUser(object $user): static;

    /**
     * Continue an existing conversation, optionally as the given user.
     */
    public function continue(string $conversationId, ?object $as = null): static;

    /**
     * Continue the given conversation for the participant, or start a new one when there is none.
     */
    public function continueOrStart(?string $conversationId, object $as): static;

    /**
     * Continue the given user's last conversation with this agent.
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

    /**
     * Remember the rows the current turn is being recorded on, or forget them once it completes.
     */
    public function recordTurn(?RecordedTurn $turn): static;

    /**
     * Get the rows the given invocation is being recorded on, if this agent opened them.
     */
    public function recordedTurn(?string $invocationId): ?RecordedTurn;
}
