<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Providers\TextProvider;

class GeneratingConversationTitle
{
    /**
     * @param  string|null  $parentInvocationId  The invocation of the agent run that opened the conversation.
     * @param  string|null  $conversationId  The ID the new conversation will be stored under, or null when the turn reserved none.
     * @param  string  $prompt  The message sent to the provider to be titled.
     */
    public function __construct(
        public string $invocationId,
        public ?string $parentInvocationId,
        public ?string $conversationId,
        public TextProvider $provider,
        public string $model,
        public string $prompt,
    ) {}
}
