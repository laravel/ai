<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class DatabaseConversationStore implements ConversationStore
{
    /**
     * Create a new conversation store instance.
     */
    public function __construct(protected ?string $connection = null)
    {
        //
    }

    /**
     * Get the most recent conversation ID for a given user.
     */
    public function latestConversationId(string|int $userId): ?string
    {
        return $this->table($this->conversationsTable())
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(string|int|null $userId, string $title): string
    {
        $conversationId = (string) Str::uuid7();

        $this->table($this->conversationsTable())->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        $this->table($this->messagesTable())->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        $this->table($this->messagesTable())->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls->values()),
            'tool_results' => json_encode($response->toolResults->values()),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record) {
                $toolCalls = collect(json_decode($record->tool_calls, true))->values();
                $toolResults = collect(json_decode($record->tool_results, true))->values();

                if ($record->role === 'user') {
                    $attachments = collect(json_decode($record->attachments, true))
                        ->map(fn (array $a) => $this->deserializeAttachment($a))
                        ->filter()
                        ->values();

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [];

                    $messages[] = new AssistantMessage(
                        $record->content ?: '',
                        $toolCalls->map(fn ($toolCall) => new ToolCall(
                            id: $toolCall['id'],
                            name: $toolCall['name'],
                            arguments: $toolCall['arguments'],
                            resultId: $toolCall['result_id'] ?? null,
                            reasoningId: $toolCall['reasoning_id'] ?? null,
                            reasoningSummary: $toolCall['reasoning_summary'] ?? null,
                        ))
                    );

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(fn ($toolResult) => new ToolResult(
                                id: $toolResult['id'],
                                name: $toolResult['name'],
                                arguments: $toolResult['arguments'],
                                result: $toolResult['result'],
                                resultId: $toolResult['result_id'] ?? null,
                            ))
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
    }

    /**
     * Reconstruct an attachment object from its serialized array representation.
     */
    protected function deserializeAttachment(array $attachment): mixed
    {
        return match ($attachment['type'] ?? '') {
            'base64-image' => new Base64Image($attachment['base64'], $attachment['mime'] ?? null),
            'local-image' => new LocalImage($attachment['path'], $attachment['mime'] ?? null),
            'stored-image' => new StoredImage($attachment['path'], $attachment['disk'] ?? null),
            'remote-image' => new RemoteImage($attachment['url'], $attachment['mime'] ?? null),
            'provider-image' => new ProviderImage($attachment['id']),
            'base64-document' => new Base64Document($attachment['base64'], $attachment['mime'] ?? null),
            'local-document' => new LocalDocument($attachment['path'], $attachment['mime'] ?? null),
            'stored-document' => new StoredDocument($attachment['path'], $attachment['disk'] ?? null),
            'remote-document' => new RemoteDocument($attachment['url'], $attachment['mime'] ?? null),
            'provider-document' => new ProviderDocument($attachment['id']),
            'base64-audio' => new Base64Audio($attachment['base64'], $attachment['mime'] ?? null),
            'local-audio' => new LocalAudio($attachment['path'], $attachment['mime'] ?? null),
            'stored-audio' => new StoredAudio($attachment['path'], $attachment['disk'] ?? null),
            'remote-audio' => new RemoteAudio($attachment['url'], $attachment['mime'] ?? null),
            default => null,
        };
    }

    /**
     * Get a query builder for the given table using the configured connection.
     */
    protected function table(string $table): Builder
    {
        return DB::connection($this->connection)->table($table);
    }

    /**
     * Resolve the conversations table name from config.
     */
    protected function conversationsTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    /**
     * Resolve the messages table name from config.
     */
    protected function messagesTable(): string
    {
        return config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }
}
