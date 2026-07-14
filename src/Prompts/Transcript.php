<?php

namespace Laravel\Ai\Prompts;

use InvalidArgumentException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;

class Transcript
{
    /**
     * Normalize and validate a client-supplied transcript into a canonical Message[] array.
     *
     * @return Message[]
     */
    public static function normalize(array $messages): array
    {
        $messages = array_map(fn ($message) => Message::tryFrom($message), array_values($messages));

        $messages = static::mergeAdjacentToolResults($messages);

        $messages = static::pairToolCalls($messages);

        $trailing = $messages[array_key_last($messages)] ?? null;

        if (! $trailing instanceof Message || $trailing->role !== MessageRole::User) {
            throw new InvalidArgumentException('A transcript must end with a user message.');
        }

        return $messages;
    }

    /**
     * Merge consecutive tool result messages into one, since providers require them grouped.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected static function mergeAdjacentToolResults(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            $previous = $merged[count($merged) - 1] ?? null;

            if ($message instanceof ToolResultMessage && $previous instanceof ToolResultMessage) {
                $merged[count($merged) - 1] = new ToolResultMessage(
                    $previous->toolResults->merge($message->toolResults)
                );

                continue;
            }

            $merged[] = $message;
        }

        return $merged;
    }

    /**
     * Strip tool calls/results that have no matching counterpart, since providers reject dangling pairs.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected static function pairToolCalls(array $messages): array
    {
        $result = [];

        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                $previous = $result[count($result) - 1] ?? null;

                $callIds = $previous instanceof AssistantMessage
                    ? $previous->toolCalls->pluck('id')->all()
                    : [];

                $matched = $message->toolResults
                    ->filter(fn ($toolResult) => in_array($toolResult->id, $callIds, true))
                    ->values();

                if ($matched->isNotEmpty()) {
                    $result[] = new ToolResultMessage($matched);
                }

                continue;
            }

            if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
                $next = $messages[$index + 1] ?? null;

                $resultIds = $next instanceof ToolResultMessage
                    ? $next->toolResults->pluck('id')->all()
                    : [];

                $matchedCalls = $message->toolCalls
                    ->filter(fn ($toolCall) => in_array($toolCall->id, $resultIds, true))
                    ->values();

                if ($matchedCalls->count() < $message->toolCalls->count()) {
                    if ($matchedCalls->isEmpty() && blank($message->content)) {
                        continue;
                    }

                    $message = new AssistantMessage($message->content, $matchedCalls);
                }
            }

            $result[] = $message;
        }

        return $result;
    }
}
