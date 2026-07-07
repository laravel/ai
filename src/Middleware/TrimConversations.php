<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

class TrimConversations
{
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
        $messages = array_values($messages);

        return $next(array_slice($messages, $this->keptStartIndex($messages)));
    }

    /**
     * Determine the index at which the kept block should begin so it starts on a user turn.
     *
     * @param  Message[]  $messages
     */
    protected function keptStartIndex(array $messages): int
    {
        $count = count($messages);

        if ($count <= $this->keep) {
            return 0;
        }

        $naive = min($count - $this->keep, $count - 1);

        for ($index = $naive; $index >= 0; $index--) {
            if ($messages[$index]->role === MessageRole::User) {
                return $index;
            }
        }

        for ($index = $naive + 1; $index < $count; $index++) {
            if ($messages[$index]->role === MessageRole::User) {
                return $index;
            }
        }

        return 0;
    }
}
