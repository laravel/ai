<?php

namespace Laravel\Ai\Contracts;

interface ParticipantAware extends ConversationStore
{
    /**
     * Get a store instance scoped to the given conversation participant.
     */
    public function forParticipant(?object $participant): static;
}
