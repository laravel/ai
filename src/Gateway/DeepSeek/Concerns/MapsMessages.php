<?php

namespace Laravel\Ai\Gateway\DeepSeek\Concerns;

use Illuminate\Support\Facades\Log;
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

        $skipToolResults = false;

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            if ($skipToolResults && $message->role === MessageRole::ToolResult) {
                continue;
            }

            $skipToolResults = false;

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $skipToolResults = $this->mapAssistantMessage($message, $chatMessages),
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
        if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ],
        ];
    }

    /**
     * Map an assistant message to Chat Completions format.
     *
     * Returns true if tool_calls were stripped due to missing reasoning_content
     * (signals the caller to skip the following ToolResultMessage).
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): bool
    {
        $msg = ['role' => 'assistant'];

        if (filled($message->content)) {
            $msg['content'] = $message->content;
        }

        $hasReasoningContent = $message instanceof AssistantMessage
            && filled($message->providerContentBlocks['reasoning_content'] ?? null);

        $hasToolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty();

        if ($hasReasoningContent && $hasToolCalls) {
            $msg['reasoning_content'] = $message->providerContentBlocks['reasoning_content'];
        }

        // Per DeepSeek docs: assistant messages with tool_calls MUST include
        // reasoning_content. If it's missing (e.g. from external conversation
        // history that didn't persist it), we warn and degrade by stripping
        // the tool_calls and signaling the caller to skip corresponding
        // ToolResultMessages.
        if ($hasToolCalls && ! $hasReasoningContent) {
            Log::warning(
                'DeepSeek assistant message with tool_calls is missing reasoning_content; stripping tool_calls and skipping following tool result messages.',
                [
                    'tool_call_ids' => $message->toolCalls->pluck('id')->values()->all(),
                ],
            );

            $chatMessages[] = $msg;

            return true;
        }

        if ($hasToolCalls) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall)
            )->all();
        }

        $chatMessages[] = $msg;

        return false;
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
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
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
