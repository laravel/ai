<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Gateway\Concerns\MapsToolResultAttachments;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

trait MapsMessages
{
    use MapsToolResultAttachments;

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
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input, $provider),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input, $provider),
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
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input, Provider $provider): void
    {
        if ($message instanceof AssistantMessage && filled($message->replayBlocks)) {
            $blocks = $this->isStateless($provider)
                ? $this->withoutStoredOnlyItems($message->replayBlocks)
                : $message->replayBlocks;

            foreach ($blocks as $block) {
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
     * Remove file_search_call items, which the API resolves by id and so rejects when store is false.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    protected function withoutStoredOnlyItems(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            fn (array $block): bool => ($block['type'] ?? null) !== 'file_search_call',
        ));
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
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input, ?Provider $provider = null): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = $this->mapFunctionCallOutput($toolResult, $provider);
        }
    }

    /**
     * Throw for a document OpenAI can't take as a file input, so it's noted instead: PDFs, text and code, and office
     * documents. A file OpenAI has stored, or one sent by URL without a declared type, is left to OpenAI.
     */
    protected function ensureToolResultDocumentType(Image|Document $file): void
    {
        if (! $file instanceof Document) {
            return;
        }

        // A file sent by ID or URL carries no type, so only one it declares is checked...
        if ($file instanceof RemoteDocument || $file instanceof ProviderDocument) {
            $mime = $file->mime === null ? null : strtolower(trim((string) strtok($file->mime, ';')));

            if ($mime === null) {
                return;
            }
        } else {
            // An inline file is sent with its type as it is, so that's what's checked...
            $mime = $file->mimeType();
        }

        $accepted = in_array($mime, [
            'application/pdf',
            'application/json',
            'application/xml',
            'application/javascript',
            'application/rtf',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.text',
        ], true) || (is_string($mime) && ! str_contains($mime, ';') && (
            str_starts_with($mime, 'text/')
            || str_starts_with($mime, 'application/vnd.openxmlformats-officedocument.')
        ));

        if (! $accepted) {
            throw new InvalidArgumentException('OpenAI can\'t take ['.($mime ?? 'unknown').'] documents.');
        }
    }

    /**
     * Map a tool result to an OpenAI function call output, with the files the tool returned.
     */
    protected function mapFunctionCallOutput(ToolResult $toolResult, ?Provider $provider = null): array
    {
        return [
            'type' => 'function_call_output',
            'call_id' => $toolResult->resultId,
            'output' => $toolResult->attachments === [] || $provider === null
                ? $toolResult->text()
                : $this->mapToolResultParts(
                    $toolResult,
                    fn (string $text): array => ['type' => 'input_text', 'text' => $text],
                    function ($file) use ($provider): array {
                        $this->ensureToolResultImageType($file, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
                        $this->ensureToolResultDocumentType($file);

                        return $this->mapAttachments(collect([$file]), $provider)[0];
                    },
                ),
        ];
    }
}
