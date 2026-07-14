<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Gateway\PendingStep;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

class TrimConversations
{
    /**
     * Create a new conversation trimming middleware.
     */
    public function __construct(public int $keep) {}

    /**
     * Handle the pending generation step.
     */
    public function handle(PendingStep $step, Closure $next)
    {
        return $next($step->withMessages($this->trim($step->messages)));
    }

    /**
     * Keep only the most recent messages, snapped to a safe conversational boundary.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected function trim(array $messages): array
    {
        $messages = array_values($messages);

        return array_slice($messages, $this->keptStartIndex($messages));
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


        for ($index = $naive; $index >= 1; $index--) {
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
