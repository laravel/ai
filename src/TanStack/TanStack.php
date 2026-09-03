<?php

namespace Laravel\Ai\TanStack;

use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Models\ConversationMessage;

/**
 * TanStack AI speaks AG-UI on the wire but stores history as its own UIMessage shape.
 *
 * See: https://tanstack.com/ai
 */
class TanStack extends AgentUserInteraction
{
    /**
     * Convert messages or conversation message models into TanStack UI messages.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public static function toUiMessages(iterable $messages): array
    {
        $ui = [];
        $owners = [];
        $results = [];

        foreach (static::uiMessagesFrom($messages) as $message) {
            // A resumed run replays tool results before the assistant message that follows them,
            // so collect them by call id and attach them once their caller is known...
            if ($message['role'] === 'tool') {
                $results[$message['toolCallId']] = $message['content'];

                continue;
            }

            // Multimodal content is skipped until a TanStack part type covers it...
            $parts = is_string($message['content'] ?? null) && filled($message['content'])
                ? [['type' => 'text', 'content' => $message['content']]]
                : [];

            foreach ($message['toolCalls'] ?? [] as $call) {
                $owners[$call['id']] = count($ui);

                $parts[] = [
                    'type' => 'tool-call',
                    'id' => $call['id'],
                    'name' => $call['function']['name'],
                    'arguments' => $call['function']['arguments'],
                    'input' => json_decode($call['function']['arguments']) ?: (object) [],
                    'state' => 'complete',
                ];
            }

            if ($parts !== []) {
                $ui[] = ['id' => $message['id'], 'role' => $message['role'], 'parts' => $parts];
            }
        }

        foreach ($results as $callId => $content) {
            if (! array_key_exists($callId, $owners)) {
                continue;
            }

            $ui[$owners[$callId]]['parts'][] = [
                'type' => 'tool-result',
                'toolCallId' => $callId,
                'content' => $content,
                'state' => 'complete',
            ];
        }

        return $ui;
    }

    /**
     * Convert messages or conversation message models into state for hydrating a TanStack client.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>}
     */
    public static function toClientState(iterable $messages): array
    {
        $messages = [...$messages];

        return [
            'messages' => static::toUiMessages($messages),
            'interrupts' => static::toInterrupts($messages),
        ];
    }
}
