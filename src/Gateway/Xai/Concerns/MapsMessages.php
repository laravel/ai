<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Laravel\Ai\Gateway\Concerns\MapsToolResultAttachments;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolResult;

trait MapsMessages
{
    use MapsToolResultAttachments;

    /**
     * Map the given Laravel messages to xAI Responses API input format.
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
     * Map a user message to xAI format.
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
     * Map an assistant message to xAI format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $reasoningBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn ($toolCall): array => [
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                ])
                ->values()
                ->all();

            foreach ($reasoningBlocks as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach ($message->toolCalls->where('reasoningId', $reasoningBlock['id']) as $toolCall) {
                    $input[] = [
                        'id' => $toolCall->id,
                        'call_id' => $toolCall->resultId,
                        'type' => 'function_call',
                        'name' => $toolCall->name,
                        'arguments' => json_encode($toolCall->arguments ?: (object) []),
                    ];
                }
            }

            foreach ($message->toolCalls->whereNull('reasoningId') as $toolCall) {
                $input[] = [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments ?: (object) []),
                ];
            }
        }

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
    }

    /**
     * Map a tool result message to xAI format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = $this->mapFunctionCallOutput($toolResult);
        }
    }

    /**
     * Map a tool result to an xAI function call output, with the files the tool returned.
     */
    protected function mapFunctionCallOutput(ToolResult $toolResult): array
    {
        return [
            'type' => 'function_call_output',
            'call_id' => $toolResult->resultId,
            'output' => $toolResult->attachments === []
                ? $toolResult->text()
                : $this->mapToolResultParts(
                    $toolResult,
                    fn (string $text): array => ['type' => 'input_text', 'text' => $text],
                    function ($file): array {
                        $this->ensureToolResultImageType($file, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

                        return $this->mapAttachments(collect([$file]))[0];
                    },
                ),
        ];
    }
}
