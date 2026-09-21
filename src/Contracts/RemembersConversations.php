<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Gateway\RunContext;

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
     * Remember the context the current run is recording its steps on.
     *
     * @internal
     */
    public function recordRunContext(?RunContext $context): static;

    /**
     * Get the context the given invocation is recording its steps on, if this agent opened it.
     *
     * @internal
     */
    public function recordedRunContext(?string $invocationId): ?RunContext;
}
