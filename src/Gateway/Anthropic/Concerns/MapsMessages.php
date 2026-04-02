<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Anthropic Messages API format.
     */
    protected function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $mapped),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $mapped),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $mapped),
            };
        }

        return $mapped;
    }

    /**
     * Map a user message to Anthropic format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$mapped): void
    {
        $content = [
            ['type' => 'text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($this->mapAttachments($message->attachments), $content);
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to Anthropic format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped): void
    {
        $content = [];
        $hasToolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty();

        if ($hasToolCalls) {
            $thinkingBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn ($toolCall) => [
                    'type' => 'thinking',
                    'thinking' => is_array($toolCall->reasoningSummary)
                        ? implode("\n", array_column($toolCall->reasoningSummary, 'text'))
                        : ($toolCall->reasoningSummary ?? ''),
                ])
                ->values()
                ->all();

            array_push($content, ...$thinkingBlocks);
        }

        if (filled($message->content)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->content,
            ];
        }

        if ($hasToolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments,
                ];
            }
        }

        if (filled($content)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }
    }

    /**
     * Map a tool result message to Anthropic format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$mapped): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->id,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }
}
