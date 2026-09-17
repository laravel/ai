<?php

namespace Laravel\Ai\Storage;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class DatabaseConversationStore implements ConversationStore, PaginatesConversations, ResolvesPendingApprovals, VerifiesConversationOwnership
{
    /**
     * Create a new conversation store instance.
     */
    public function __construct(protected ?string $connection = null)
    {
        //
    }

    /**
     * Get the participant's most recent conversation ID with the given agent.
     *
     * @param  class-string<Agent>  $agent
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return $this->table($this->messagesTable())
            ->where('participant_type', $participantType)
            ->where('participant_id', $participantId)
            ->where('agent', $agent)
            ->orderByDesc('id')
            ->value('conversation_id');
    }

    /**
     * Determine whether the given conversation was stored for the given participant.
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        $conversation = $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->first(['participant_type', 'participant_id']);

        return $conversation !== null
            && $conversation->participant_type === $participantType
            && (string) $conversation->participant_id === (string) $participantId;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $conversationId = $id ?? (string) Str::uuid7();

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
     *
     * @param  class-string<Agent>  $agent
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $agent,
            'role' => 'user',
            'content' => $message->content,
            'attachments' => $message->attachments->toJson(),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'approval_state' => null,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store a new assistant message for the given conversation, or null when nothing was stored.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $steps = $this->stepsFor($prompt, $response);

        if ($prompt->hasApprovalDecisions() && blank($response->text) && $steps->every(fn (array $step) => $step['tool_calls'] === [])) {
            return null;
        }

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'steps' => $steps->toJson(),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($this->messageMeta($response)),
            'approval_state' => $this->approvalState($response),
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Serialize the turn's steps, one entry per model round-trip.
     *
     * @return Collection<int, array{tool_calls: array, provider_blocks: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        if ($response->steps->isNotEmpty()) {
            return $response->steps->values()->map(fn (Step $step): array => [
                'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults),
                'provider_blocks' => $step->providerContentBlocks,
            ]);
        }

        // A resume that ran no step only carries the approval results storeApprovalResults() already wrote to the paused row...
        return collect([[
            'tool_calls' => $this->toolCallsFor(
                $response->toolCalls->all(),
                $prompt->hasApprovalDecisions() ? [] : $response->toolResults->all(),
            ),
            'provider_blocks' => [],
        ]]);
    }

    /**
     * Pair a step's tool calls with the results they were answered by, one entry per call.
     *
     * @param  iterable<int, ToolCall>  $toolCalls
     * @param  iterable<int, ToolResult>  $toolResults
     * @return list<array<string, mixed>>
     */
    protected function toolCallsFor(iterable $toolCalls, iterable $toolResults): array
    {
        $results = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

        return collect($toolCalls)->map(function (ToolCall $toolCall) use ($results): array {
            $result = $results->get($toolCall->id);

            return [
                ...$toolCall->toArray(),
                ...$result === null ? [] : Arr::only($result->toArray(), ['result', 'denied', 'failed']),
            ];
        })->values()->all();
    }

    /**
     * Determine whether a stored tool call has been answered by its tool.
     *
     * @param  array<string, mixed>  $toolCall
     */
    protected function isAnswered(array $toolCall): bool
    {
        return array_key_exists('result', $toolCall);
    }

    /**
     * Mark a paused assistant row with the tool-call IDs pending a decision, or null when the turn is not a pause.
     */
    protected function approvalState(AgentResponse $response): ?string
    {
        if (! $response->hasPendingApprovals()) {
            return null;
        }

        return json_encode([
            'pending' => $response->pendingApprovals->mapWithKeys(fn ($approval) => [$approval->id => $approval->reason])->all(),
        ]);
    }

    /**
     * Decode a stored JSON column.
     *
     * @return array<array-key, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    /**
     * The reasons a stored row is still awaiting a decision on, keyed by tool call ID.
     *
     * @return Collection<string, string>
     */
    protected function pendingReasons(object $record): Collection
    {
        $pending = $this->decoded($record->approval_state)['pending'] ?? [];

        return collect(is_array($pending) ? $pending : []);
    }

    /**
     * Determine whether a stored row is an assistant turn still awaiting a decision.
     */
    protected function awaitsDecision(?object $record): bool
    {
        return $record?->role === 'assistant' && $this->pausedCallIds($record) !== [];
    }

    /**
     * Get the tool-call IDs a stored row recorded as pending a decision.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        return $this->pendingReasons($record)->keys()->all();
    }

    /**
     * Rebuild the approvals a stored row is still awaiting a decision on.
     *
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsIn(object $record): Collection
    {
        $reasons = $this->pendingReasons($record);

        return $this->decodedSteps($record)->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter(fn (array $toolCall) => $reasons->has($toolCall['id'] ?? ''))
            ->map(fn (array $toolCall) => new PendingApproval(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'],
                $reasons[$toolCall['id']],
            ))->values();
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
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
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
     * Build the message meta payload from the response meta.
     *
     * @return array<string, mixed>
     */
    protected function messageMeta(AgentResponse $response): array
    {
        $meta = (array) json_decode(json_encode($response->meta), true);

        if (filled($response->reasoning)) {
            $meta['reasoning'] = $response->reasoning;
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
        $records = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $replayFrom = $this->pausedTurnStartIndex($records);

        return $records->flatMap(fn (object $record, int $index): array => $record->role === 'user'
            ? [$this->userMessageFrom($record)]
            : $this->assistantTurnFrom($record, replayRawBlocks: $index >= $replayFrom));
    }

    /**
     * Get the index the turn awaiting a decision starts at, or a past-the-end index when no turn is paused.
     *
     * @param  Collection<int, object>  $records
     */
    protected function pausedTurnStartIndex(Collection $records): int
    {
        if (! $this->awaitsDecision($records->last())) {
            return $records->count();
        }

        $turnStart = $records->reverse()->search(fn (object $record): bool => $record->role === 'user');

        return $turnStart === false ? 0 : $turnStart + 1;
    }

    /**
     * Rebuild a stored user turn.
     */
    protected function userMessageFrom(object $record): Message
    {
        $attachments = $this->rehydrateAttachments($record->attachments);

        return $attachments->isNotEmpty()
            ? new UserMessage($record->content, $attachments)
            : new Message('user', $record->content);
    }

    /**
     * Rebuild a stored assistant turn step by step, so every tool result answers the message that made its call.
     *
     * @return array<int, Message>
     */
    protected function assistantTurnFrom(object $record, bool $replayRawBlocks): array
    {
        $pending = $this->pausedCallIds($record);
        $provider = $this->decoded($record->meta)['provider'] ?? null;
        $steps = $this->decodedSteps($record);
        $lastStep = $steps->count() - 1;

        return $steps->flatMap(function (array $step, int $index) use ($pending, $provider, $replayRawBlocks, $lastStep, $record): array {
            $content = $index === $lastStep ? (string) $record->content : '';

            $replayed = collect($step['tool_calls'])
                ->filter(fn (array $toolCall) => $this->isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true))
                ->values();

            $toolCalls = $replayed->map(ToolCall::fromArray(...));
            $toolResults = $replayed->filter($this->isAnswered(...))->map(ToolResult::fromArray(...))->values();

            $providerBlocks = $replayRawBlocks ? $step['provider_blocks'] : [];

            $isBlank = $content === '' && $toolCalls->isEmpty() && $providerBlocks === [];

            $messages = $isBlank ? [] : [new AssistantMessage($content, $toolCalls, $providerBlocks, $provider)];

            if ($toolResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($toolResults);
            }

            return $messages;
        })->all();
    }

    /**
     * Decode a stored row's steps.
     *
     * @return Collection<int, array{tool_calls: array, provider_blocks: array}>
     */
    protected function decodedSteps(object $record): Collection
    {
        return collect($this->decoded($record->steps))->map(fn (array $step): array => [
            'tool_calls' => array_values($step['tool_calls'] ?? []),
            'provider_blocks' => $step['provider_blocks'] ?? [],
        ])->values();
    }

    /**
     * Paginate the given conversation's messages, newest first.
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $cursor)
            ->through(fn (object $record): StoredMessage => StoredMessage::fromArray((array) $record));
    }

    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array
    {
        $newest = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first(['role', 'steps', 'approval_state']);

        if (! $this->awaitsDecision($newest)) {
            return [];
        }

        $answered = $this->decodedSteps($newest)
            ->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter($this->isAnswered(...))
            ->pluck('id')
            ->all();

        return $this->pendingApprovalsIn($newest)
            ->reject(fn (PendingApproval $approval) => in_array($approval->id, $answered, true))
            ->values()
            ->all();
    }

    /**
     * Rehydrate attachments from their stored JSON representation.
     *
     * @return Collection<int, File>
     */
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
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param  array<int, ToolResult>  $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        DB::connection($this->connection)->transaction(function () use ($conversationId, $participantType, $participantId, $toolResults, $resultIds) {
            $paused = $this->table($this->messagesTable())
                ->where('conversation_id', $conversationId)
                ->when($participantId === null,
                    fn ($query) => $query->whereNull('participant_type')->whereNull('participant_id'),
                    fn ($query) => $query->where('participant_type', $participantType)->where('participant_id', $participantId))
                ->where('role', 'assistant')
                ->whereNotNull('approval_state')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $row = $paused->first(fn ($record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $paused->first() === null ? collect() : $this->pendingApprovalsIn($paused->first()),
                );
            }

            $resolved = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

            $steps = $this->decodedSteps($row)->map(function (array $step) use ($resolved): array {
                $step['tool_calls'] = array_map(function (array $toolCall) use ($resolved): array {
                    $result = $resolved->get($toolCall['id'] ?? '');

                    return $result === null || $this->isAnswered($toolCall)
                        ? $toolCall
                        // Arguments come along because an edited approval runs the tool with different ones than the call asked for...
                        : [...$toolCall, ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed'])];
                }, $step['tool_calls']);

                return $step;
            });

            $pending = $this->pendingReasons($row)->except($resultIds);

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update([
                    'steps' => $steps->toJson(),
                    'approval_state' => json_encode(['pending' => $pending->all()]),
                    'updated_at' => now(),
                ]);
        });
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
