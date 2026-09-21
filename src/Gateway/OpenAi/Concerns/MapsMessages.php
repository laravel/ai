<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to OpenAI Responses API input format.
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions, Provider $provider): array
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
                MessageRole::User => $this->mapUserMessage($message, $input, $provider),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to OpenAI format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input, Provider $provider): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments, $provider));
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
        if ($message instanceof AssistantMessage && filled($message->replayBlocks)) {
            foreach ($message->replayBlocks as $block) {
                $input[] = $block;
            }

            return;
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $reasoningBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn ($toolCall) => Arr::whereNotNull([
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                    'encrypted_content' => $toolCall->reasoningEncryptedContent,
                ]))
                ->values()
                ->all();

            foreach ($reasoningBlocks as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach ($message->toolCalls->where('reasoningId', $reasoningBlock['id']) as $toolCall) {
                    $input[] = $this->functionCallItem($toolCall);
                }
            }

            foreach ($message->toolCalls->whereNull('reasoningId') as $toolCall) {
                $input[] = $this->functionCallItem($toolCall);
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
     * Map a tool call to a function_call input item, keeping the item id only when OpenAI issued it and its reasoning survived.
     *
     * @return array<string, mixed>
     */
    protected function functionCallItem(ToolCall $toolCall): array
    {
        // A replayed call whose reasoning was dropped cannot carry its item id, as the API rejects an fc_ item with no reasoning item before it...
        return Arr::whereNotNull([
            'id' => $toolCall->reasoningId !== null && str_starts_with($toolCall->id, 'fc_') ? $toolCall->id : null,
            'call_id' => $toolCall->resultId,
            'type' => 'function_call',
            'name' => $toolCall->name,
            'arguments' => json_encode($toolCall->arguments ?: (object) []),
        ]);
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
                'output' => $toolResult->text(),
            ];
        }
    }
}
