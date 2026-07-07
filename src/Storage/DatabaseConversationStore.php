<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\File;
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
     * Get the most recent conversation ID for a given participant.
     */
    public function latestConversationId(string|int $participantId, ?string $participantType): ?string
    {
        $table = $this->conversationsTable();

        $query = $this->table($table)
            ->where($this->ownerColumnFor($table), $participantId)
            ->orderBy('updated_at', 'desc');

        if ($this->hasTypeColumn($table)) {
            $query->where('participant_type', $participantType);
        }

        return $query->first()?->id;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(string|int|null $participantId, string $title, ?string $participantType): string
    {
        $conversationId = (string) Str::uuid7();

        $table = $this->conversationsTable();

        $this->table($table)->insert(array_filter([
            'id' => $conversationId,
            $this->ownerColumnFor($table) => $participantId,
            'participant_type' => $this->hasTypeColumn($table) ? $participantType : null,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value) => $value !== null));

        return $conversationId;
    }

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, string|int|null $participantId, ?string $participantType, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $table = $this->messagesTable();

        $this->table($table)->insert($this->messageAttributes($messageId, $table, $conversationId, $participantId, $participantType, $now, [
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $participantId, ?string $participantType, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $table = $this->messagesTable();

        $this->table($table)->insert($this->messageAttributes($messageId, $table, $conversationId, $participantId, $participantType, $now, [
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls->values()),
            'tool_results' => json_encode($response->toolResults->values()),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Update the conversation's activity timestamp.
     */
    protected function touchConversation(string $conversationId, mixed $timestamp): void
    {
        $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->update(['updated_at' => $timestamp]);
    }

    /**
     * Build the message row attributes, adapting to the installed schema.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $table, string $conversationId, string|int|null $participantId, ?string $participantType, mixed $now, array $attributes): array
    {
        return array_filter(array_merge($attributes, [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            $this->ownerColumnFor($table) => $participantId,
            'participant_type' => $this->hasTypeColumn($table) ? $participantType : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]), fn ($value) => $value !== null);
    }

    /**
     * Resolve the owner column for the given table, supporting legacy user_id schemas.
     */
    protected function ownerColumnFor(string $table): string
    {
        return $this->schema()->hasColumn($table, 'participant_id') ? 'participant_id' : 'user_id';
    }

    /**
     * Determine whether the given table records a participant type.
     */
    protected function hasTypeColumn(string $table): bool
    {
        return $this->schema()->hasColumn($table, 'participant_type');
    }

    /**
     * Get the configured connection's schema builder.
     */
    protected function schema()
    {
        return Schema::connection($this->connection);
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
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [
                        new AssistantMessage(
                            $record->content ?: '',
                            $toolCalls->map(ToolCall::fromArray(...)),
                        ),
                    ];

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(ToolResult::fromArray(...)),
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
    }

    protected function rehydrateAttachments(string $attachments): Collection
    {
        $decoded = json_decode($attachments, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('Stored conversation attachments must be a JSON array.');
        }

        if ($decoded === []) {
            return collect();
        }

        return collect($decoded)
            ->map(function (mixed $attachment) {
                if (! is_array($attachment)) {
                    throw new InvalidArgumentException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->values();
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
