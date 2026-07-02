<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasConversationMiddleware;
use Laravel\Ai\Messages\Message;

use function Laravel\Ai\pipeline;

trait ManagesConversationMiddleware
{
    /**
     * Run the agent's conversation middleware over the outgoing messages.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected function applyConversationMiddleware(Agent $agent, array $messages): array
    {
        if (! $agent instanceof HasConversationMiddleware) {
            return $messages;
        }

        return pipeline()
            ->send($messages)
            ->through($agent->conversationMiddleware())
            ->thenReturn();
    }
}
