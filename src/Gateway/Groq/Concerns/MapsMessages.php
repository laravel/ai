<?php

namespace Laravel\Ai\Gateway\Groq\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Chat Completions messages format.
     */
    protected function mapMessagesToChat(array $messages, ?string $instructions = null): array
    {
        $chatMessages = [];

        if (filled($instructions)) {
            $chatMessages[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $chatMessages),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $chatMessages),
            };
        }

        return $chatMessages;
    }

    /**
     * Map a user message to Chat Completions format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ];

            $chatMessages[] = [
                'role' => 'user',
                'content' => $content,
            ];
        } else {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];
        }
    }

    /**
     * Map an assistant message to Chat Completions format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $msg = ['role' => 'assistant'];

        if (filled($message->content)) {
            $msg['content'] = $message->content;
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn (ToolCall $tc) => $this->serializeToolCallToChat($tc)
            )->all();
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Chat Completions format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Chat Completions array format.
     */
    protected function serializeToolCallToChat(ToolCall $tc): array
    {
        return [
            'id' => $tc->resultId ?? $tc->id,
            'type' => 'function',
            'function' => [
                'name' => $tc->name,
                'arguments' => json_encode($tc->arguments),
            ],
        ];
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? json_encode($output) : strval($output);
    }
}
