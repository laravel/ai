<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use HashContext;
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
    protected function mapMessages(array $messages, ?HashContext $thinkingPrefix = null): array
    {
        $mapped = [];
        $fingerprinted = 0;

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            for (; $thinkingPrefix !== null && $fingerprinted < count($mapped); $fingerprinted++) {
                $this->extendThinkingPrefix($thinkingPrefix, $mapped[$fingerprinted]);
            }

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $mapped),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $mapped, $thinkingPrefix ? hash_final(hash_copy($thinkingPrefix)) : null),
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
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped, ?string $thinkingFingerprint = null): void
    {
        if ($message instanceof AssistantMessage && filled($message->replayBlocks)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->replayBlocks),
            ];

            return;
        }

        $hasToolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty();

        // Anthropic rejects thinking whose system prompt, tools, or earlier messages changed since it was issued...
        $content = $hasToolCalls && $thinkingFingerprint !== null && $message->toolCalls->first()->thinkingFingerprint === $thinkingFingerprint
            ? $message->toolCalls->first()->thinkingBlocks ?? []
            : [];

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
                    'input' => $toolCall->arguments ?: (object) [],
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
                'content' => $toolResult->text(),
            ];
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Ensure tool_use and server_tool_use content blocks encode empty input as an object for replay.
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block): array {
            if (in_array($block['type'] ?? '', ['tool_use', 'server_tool_use'], true)) {
                $block['input'] = (object) ($block['input'] ?? []);
            }

            return $block;
        }, $content);
    }
}
