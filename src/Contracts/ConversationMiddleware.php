<?php

namespace Laravel\Ai\Contracts;

use Closure;
use Laravel\Ai\Messages\Message;

interface ConversationMiddleware
{
    /**
     * Transform the outgoing messages before they are sent to the provider.
     *
     * @param  Message[]  $messages
     * @param  Closure(Message[]): Message[]  $next
     * @return Message[]
     */
    public function handle(array $messages, Closure $next): array;
}
