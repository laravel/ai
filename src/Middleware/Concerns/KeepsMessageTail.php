<?php

namespace Laravel\Ai\Middleware\Concerns;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

trait KeepsMessageTail
{
    /**
     * Split the messages into [older, kept] at a safe conversational boundary near the last N.
     *
     * @param  Message[]  $messages
     * @return array{0: Message[], 1: Message[]}
     */
    protected function splitMessageTail(array $messages, int $keep): array
    {
        $messages = array_values($messages);

        $start = $this->keptStartIndex($messages, $keep);

        return [
            array_slice($messages, 0, $start),
            array_slice($messages, $start),
        ];
    }

    /**
     * Keep the last N messages, snapped to a safe conversational boundary.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected function keepMessageTail(array $messages, int $keep): array
    {
        return $this->splitMessageTail($messages, $keep)[1];
    }

    /**
     * Determine the index at which the kept block should begin so it starts on a user turn.
     *
     * @param  Message[]  $messages
     */
    protected function keptStartIndex(array $messages, int $keep): int
    {
        $count = count($messages);

        if ($count <= $keep) {
            return 0;
        }

        $naive = min($count - $keep, $count - 1);

        for ($i = $naive; $i >= 0; $i--) {
            if ($messages[$i]->role === MessageRole::User) {
                return $i;
            }
        }

        for ($i = $naive + 1; $i < $count; $i++) {
            if ($messages[$i]->role === MessageRole::User) {
                return $i;
            }
        }

        return 0;
    }
}
