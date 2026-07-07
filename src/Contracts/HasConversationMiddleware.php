<?php

namespace Laravel\Ai\Contracts;

interface HasConversationMiddleware
{
    /**
     * Get the middleware that should transform the conversation before it is sent.
     *
     * @return array<int, callable|object>
     */
    public function conversationMiddleware(): array;
}
