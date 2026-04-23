<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Ollama Chat API messages format.
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
     * Map a user message to Ollama Chat API format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => $message->content,
            'images' => $this->mapAttachments($message->attachments),
        ];
    }

    /**
     * Map an assistant message to Ollama Chat API format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $msg = [
            'role' => 'assistant',
            'content' => $message->content ?? '',
        ];

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall)
            )->all();
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Ollama Chat API format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_name' => $toolResult->name,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Ollama Chat API array format.
     *
     * Ollama's /api/chat message history shape only uses the `function` object for assistant tool calls — no top-level `type` key.
     *
     * That key belongs on the /api/chat `tools` definitions, not the message history.
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'function' => [
                'name' => $toolCall->name,
                'arguments' => $toolCall->arguments ?: (object) [],
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
