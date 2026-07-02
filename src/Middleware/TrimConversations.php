<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Contracts\ConversationMiddleware;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Middleware\Concerns\KeepsMessageTail;

class TrimConversations implements ConversationMiddleware
{
    use KeepsMessageTail;

    /**
     * Create a new conversation trimming middleware.
     */
    public function __construct(public int $keep) {}

    /**
     * Keep only the most recent messages, snapped to a safe conversational boundary.
     *
     * @param  Message[]  $messages
     * @param  Closure(Message[]): Message[]  $next
     * @return Message[]
     */
    public function handle(array $messages, Closure $next): array
    {
        return $next($this->keepMessageTail($messages, $this->keep));
    }
}
