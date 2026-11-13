<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Messages\UserMessage;

interface StoresUserMessages
{
    /**
     * Store a new user message for the given conversation and return its ID.
     *
     * @param  class-string<Agent>  $agent
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string;
}
