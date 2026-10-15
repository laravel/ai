<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\TextResponse;

class ConversationTitleGenerated
{
    /**
     * @param  string|null  $parentInvocationId  The invocation of the agent run that opened the conversation.
     * @param  string|null  $conversationId  The ID the new conversation will be stored under, or null when the turn reserved none.
     * @param  string  $prompt  The message sent to the provider to be titled.
     * @param  float  $time  Wall time spent in the provider call, in milliseconds.
     */
    public function __construct(
        public string $invocationId,
        public ?string $parentInvocationId,
        public ?string $conversationId,
        public TextProvider $provider,
        public string $model,
        public string $prompt,
        public TextResponse $response,
        public float $time,
    ) {}
}
