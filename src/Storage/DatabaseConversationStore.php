<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\RecordsApprovalResults;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class DatabaseConversationStore implements ConversationStore, RecordsApprovalResults, VerifiesConversationOwnership
{
    /**
     * Create a new conversation store instance.
     */
    public function __construct(protected ?string $connection = null)
    {
        //
    }

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param  array<int, ToolResult>  $toolResults
     */
    public function recordApprovalResults(string $conversationId, string|int|null $participantId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        DB::connection($this->connection)->transaction(function () use ($conversationId, $participantId, $toolResults, $resultIds) {
            $row = $this->table($this->messagesTable())
                ->where('conversation_id', $conversationId)
                ->when($participantId === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $participantId))
                ->where('role', 'assistant')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->first(fn ($record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                return;
            }

            $merged = array_merge(
                json_decode($row->tool_results, true) ?: [],
                json_decode(json_encode($toolResults), true),
            );

            $pending = array_values(array_diff($this->pausedCallIds($row), $resultIds));

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update([
                    'tool_results' => json_encode($merged),
                    'approval_state' => json_encode(['version' => 1, 'pending' => $pending]),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Determine whether the conversation belongs to the given participant.
     */
    public function conversationBelongsTo(string $conversationId, string|int|null $participantId): bool
    {
        $conversation = $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->first();

        return $conversation !== null && (string) $conversation->user_id === (string) $participantId;
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

        $now = now();

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
            'approval_state' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $toolResults = $response->toolResults->values();

        if ($prompt->resume !== null) {
            $existing = $this->existingToolResultIds($conversationId);

            $toolResults = $toolResults->reject(fn (ToolResult $result) => in_array($result->id, $existing, true))->values();
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
            'tool_results' => json_encode($toolResults),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($this->messageMeta($response)),
            'approval_state' => $this->approvalState($response),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Get every tool-result id already recorded in the conversation.
     *
     * @return array<int, string>
     */
    protected function existingToolResultIds(string $conversationId): array
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->pluck('tool_results')
            ->flatMap(fn ($results) => collect(json_decode($results, true))->pluck('id'))
            ->filter()
            ->all();
    }

    /**
     * Mark a paused assistant row with the tool-call ids awaiting a decision, or null when the turn is not a pause.
     */
    protected function approvalState(AgentResponse $response): ?string
    {
        if (! $response->awaitingApproval()) {
            return null;
        }

        return json_encode([
            'version' => 1,
            'pending' => $response->pendingApprovals->pluck('id')->values()->all(),
        ]);
    }

    /**
     * Get the tool-call ids a stored row recorded as awaiting a decision.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        $state = json_decode($record->approval_state ?? 'null', true);

        return is_array($state) && is_array($state['pending'] ?? null) ? $state['pending'] : [];
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
     * Build the message meta payload, tucking a paused turn's raw provider blocks alongside the response meta.
     *
     * @return array<string, mixed>
     */
    protected function messageMeta(AgentResponse $response): array
    {
        $meta = (array) json_decode(json_encode($response->meta), true);

        if (filled($blocks = $response->pausedProviderContentBlocks())) {
            $meta['provider_content_blocks'] = $blocks;
        }

        return $meta;
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
                    $callIds = $toolCalls->pluck('id')->all();

                    [$priorResults, $ownResults] = $toolResults->partition(
                        fn (array $toolResult) => ! in_array($toolResult['id'], $callIds, true)
                    );

                    $ownResultIds = $ownResults->pluck('id')->all();

                    [$resolvedCalls, $pendingCalls] = $toolCalls->partition(
                        fn (array $toolCall) => in_array($toolCall['id'], $ownResultIds, true)
                    );

                    $pausedCallIds = $this->pausedCallIds($record);
                    $isPause = $pendingCalls->isNotEmpty()
                        && $pendingCalls->every(fn (array $toolCall) => in_array($toolCall['id'], $pausedCallIds, true));

                    $messages = [];

                    if ($priorResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage($priorResults->map(ToolResult::fromArray(...))->values());
                    }

                    if (! $isPause) {
                        if ($resolvedCalls->isNotEmpty()) {
                            $messages[] = new AssistantMessage('', $resolvedCalls->map(ToolCall::fromArray(...))->values());
                            $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
                        }

                        if (filled($record->content)) {
                            $messages[] = new AssistantMessage($record->content);
                        }

                        return $messages;
                    }

                    $providerContentBlocks = ((array) json_decode($record->meta ?? '[]', true))['provider_content_blocks'] ?? [];

                    if (filled($providerContentBlocks)) {
                        $messages[] = new AssistantMessage($record->content, $toolCalls->map(ToolCall::fromArray(...))->values(), $providerContentBlocks);

                        if ($ownResults->isNotEmpty()) {
                            $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
                        }

                        return $messages;
                    }

                    if ($resolvedCalls->isNotEmpty()) {
                        $messages[] = new AssistantMessage('', $resolvedCalls->map(ToolCall::fromArray(...))->values());
                        $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
                    }

                    $messages[] = new AssistantMessage($record->content, $pendingCalls->map(ToolCall::fromArray(...))->values());

                    return $messages;
                }

                if ($toolResults->isNotEmpty()) {
                    $messages = [new ToolResultMessage($toolResults->map(ToolResult::fromArray(...)))];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            })
            ->skipWhile(fn (Message $message) => $message instanceof ToolResultMessage)
            ->values();
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
