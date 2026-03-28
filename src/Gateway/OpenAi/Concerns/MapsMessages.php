<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to OpenAI Responses API input format.
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions = null): array
    {
        $input = [];

        if (filled($instructions)) {
            $input[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $input),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to OpenAI format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        $input[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to OpenAI format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if (filled($message->content)) {
            $input[] = [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $message->content,
                    ],
                ],
            ];
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $reasoningBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn ($toolCall) => [
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary,
                ])
                ->values()
                ->all();

            array_push($input, ...$reasoningBlocks);

            foreach ($message->toolCalls as $toolCall) {
                $input[] = [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments),
                ];
            }
        }
    }

    /**
     * Map a tool result message to OpenAI format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId,
                'output' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }
}
