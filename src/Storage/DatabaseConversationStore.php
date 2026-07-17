<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->table($this->conversationsTable())
            ->where('participant_type', $participantType)
            ->where('participant_id', $participantId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(string $participantType, string|int $participantId, string $title): string
    {
        $conversationId = (string) Str::uuid7();

        $this->table($this->conversationsTable())->insert([
            'id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
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
    public function storeAssistantMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
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
     * Build the message row attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $conversationId, string $participantType, string|int $participantId, mixed $now, array $attributes): array
    {
        return array_merge($attributes, [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
            ->flatMap(function ($record): array {
                $toolCalls = collect(json_decode((string) $record->tool_calls, true))->values();
                $toolResults = collect(json_decode((string) $record->tool_results, true))->values();

                if ($record->role === 'user') {
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    if ($toolResults->isEmpty()) {
                        return filled($record->content)
                            ? [new AssistantMessage($record->content)]
                            : [];
                    }

                    $messages = [
                        new AssistantMessage(
                            '',
                            $toolCalls->map(ToolCall::fromArray(...)),
                        ),
                        new ToolResultMessage(
                            $toolResults->map(ToolResult::fromArray(...)),
                        ),
                    ];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
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
            ->map(function (mixed $attachment): ?File {
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
