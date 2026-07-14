<?php

namespace Laravel\Ai\Contracts;

interface VerifiesConversationOwnership
{
    /**
     * Determine whether the conversation belongs to the given participant.
     */
    public function conversationBelongsTo(string $conversationId, string|int|null $participantId): bool;
}
