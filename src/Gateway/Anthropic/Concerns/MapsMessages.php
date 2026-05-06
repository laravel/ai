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

        $mapped[] = $this->withCacheControl(['role' => 'user', 'content' => $content], $message);
    }

    /**
     * Map an assistant message to Anthropic format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped): void
    {
        if ($message instanceof AssistantMessage && filled($message->providerContentBlocks)) {
            $mapped[] = $this->withCacheControl([
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->providerContentBlocks),
            ], $message);

            return;
        }

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
                    'input' => $toolCall->arguments ?: (object) [],
                ];
            }
        }

        if (filled($content)) {
            $mapped[] = $this->withCacheControl(['role' => 'assistant', 'content' => $content], $message);
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

        $mapped[] = $this->withCacheControl(['role' => 'user', 'content' => $content], $message);
    }

    /**
     * Attach a `cache_control` breakpoint to the last content block of the
     * mapped message when the source carries one in `providerOptions`.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function withCacheControl(array $entry, Message $message): array
    {
        if (! isset($message->providerOptions['cache_control'])) {
            return $entry;
        }

        if (! is_array($entry['content']) || count($entry['content']) === 0) {
            return $entry;
        }

        $lastIndex = array_key_last($entry['content']);
        $entry['content'][$lastIndex]['cache_control'] = $message->providerOptions['cache_control'];

        return $entry;
    }
}
