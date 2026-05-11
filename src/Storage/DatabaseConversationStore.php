<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
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
        $meta = $response->meta->toArray();

        if ($response->messages->isNotEmpty()) {
            $meta['conversation_message_sequence'] = $response->messages
                ->map(fn (Message $message) => $this->serializeMessage($message))
                ->values()
                ->all();
        }

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
            'meta' => json_encode($meta),
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
                if ($record->role === 'user') {
                    return [new Message('user', $record->content)];
                }

                $meta = json_decode($record->meta, true) ?: [];

                if (isset($meta['conversation_message_sequence']) && is_array($meta['conversation_message_sequence'])) {
                    return $this->hydrateMessageSequence($record, $meta['conversation_message_sequence']);
                }

                return $this->hydrateLegacyAssistantRecord($record);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            return [
                'role' => MessageRole::Assistant->value,
                'content' => $message->content,
                'tool_call_keys' => $message->toolCalls
                    ->map(fn (ToolCall $toolCall) => $this->itemKey($toolCall))
                    ->values()
                    ->all(),
            ];
        }

        if ($message instanceof ToolResultMessage) {
            return [
                'role' => MessageRole::ToolResult->value,
                'tool_result_keys' => $message->toolResults
                    ->map(fn (ToolResult $toolResult) => $this->itemKey($toolResult))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'role' => $message->role->value,
            'content' => $message->content,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sequence
     * @return Collection<int, Message>
     */
    private function hydrateMessageSequence(object $record, array $sequence): Collection
    {
        $toolCalls = $this->hydrateToolCalls(json_decode($record->tool_calls, true) ?: []);
        $toolResults = $this->hydrateToolResults(json_decode($record->tool_results, true) ?: []);
        $toolCallsByKey = $toolCalls->keyBy(fn (ToolCall $toolCall) => $this->itemKey($toolCall));
        $toolResultsByKey = $toolResults->keyBy(fn (ToolResult $toolResult) => $this->itemKey($toolResult));

        return collect($sequence)
            ->map(fn (array $message) => match ($message['role'] ?? null) {
                MessageRole::Assistant->value => new AssistantMessage(
                    (string) ($message['content'] ?? ''),
                    collect($message['tool_call_keys'] ?? [])
                        ->map(fn (string $key) => $toolCallsByKey->get($key))
                        ->filter()
                        ->values()
                ),
                MessageRole::ToolResult->value => new ToolResultMessage(
                    collect($message['tool_result_keys'] ?? [])
                        ->map(fn (string $key) => $toolResultsByKey->get($key))
                        ->filter()
                        ->values()
                ),
                default => new Message(
                    MessageRole::tryFrom((string) ($message['role'] ?? '')) ?? MessageRole::Assistant,
                    $message['content'] ?? ''
                ),
            })
            ->values();
    }

    /**
     * @return Collection<int, Message>
     */
    private function hydrateLegacyAssistantRecord(object $record): Collection
    {
        $toolCalls = $this->hydrateToolCalls(json_decode($record->tool_calls, true) ?: []);
        $toolResults = $this->hydrateToolResults(json_decode($record->tool_results, true) ?: []);

        if ($toolCalls->isEmpty()) {
            return collect([new AssistantMessage($record->content)]);
        }

        $resultsByCallId = $toolResults->keyBy(fn (ToolResult $result) => $this->itemKey($result));

        $messages = $toolCalls
            ->groupBy(fn (ToolCall $toolCall) => $toolCall->reasoningId ?? '')
            ->values()
            ->flatMap(function (Collection $group) use ($resultsByCallId) {
                $group = $group->values();
                $emitted = [new AssistantMessage('', $group)];

                $groupResults = $group
                    ->map(fn (ToolCall $toolCall) => $resultsByCallId->get($this->itemKey($toolCall)))
                    ->filter()
                    ->values();

                if ($groupResults->isNotEmpty()) {
                    $emitted[] = new ToolResultMessage($groupResults);
                }

                return $emitted;
            });

        if (filled($record->content)) {
            $messages->push(new AssistantMessage($record->content));
        }

        return $messages;
    }

    /**
     * @return Collection<int, ToolCall>
     */
    private function hydrateToolCalls(array $toolCalls): Collection
    {
        return collect($toolCalls)->values()->map(fn (array $toolCall) => new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['name'],
            arguments: $toolCall['arguments'],
            resultId: $toolCall['result_id'] ?? null,
            reasoningId: $toolCall['reasoning_id'] ?? null,
            reasoningSummary: $toolCall['reasoning_summary'] ?? null,
        ));
    }

    /**
     * @return Collection<int, ToolResult>
     */
    private function hydrateToolResults(array $toolResults): Collection
    {
        return collect($toolResults)->values()->map(fn (array $toolResult) => new ToolResult(
            id: $toolResult['id'],
            name: $toolResult['name'],
            arguments: $toolResult['arguments'],
            result: $toolResult['result'],
            resultId: $toolResult['result_id'] ?? null,
        ));
    }

    private function itemKey(ToolCall|ToolResult $item): string
    {
        return $item->resultId ?? $item->id;
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
