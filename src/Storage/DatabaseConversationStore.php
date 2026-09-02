<?php

namespace Laravel\Ai\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
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
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
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
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
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

        $toolResults = $response->toolResults->values();

        if ($prompt->hasApprovalDecisions()) {
            $existing = $this->existingToolResultIds($conversationId);

            $toolResults = $toolResults->reject(fn (ToolResult $result) => in_array($result->id, $existing, true))->values();

            if (blank($response->text) && $response->toolCalls->isEmpty() && $toolResults->isEmpty()) {
                return null;
            }
        }

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls->values()),
            'tool_results' => json_encode($toolResults),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($this->messageMeta($response)),
            'approval_state' => $this->approvalState($response),
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Get every tool-result ID recorded on the conversation's approval-paused rows, the only rows a resume can duplicate.
     *
     * @return array<int, string>
     */
    protected function existingToolResultIds(string $conversationId): array
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('approval_state')
            ->where('tool_results', '!=', '[]')
            ->pluck('tool_results')
            ->flatMap(fn ($results) => collect(json_decode($results, true))->pluck('id'))
            ->filter()
            ->all();
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
     * Get the tool-call IDs a stored row recorded as pending a decision.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        $state = json_decode($record->approval_state ?? 'null', true);

        return is_array($state) && is_array($state['pending'] ?? null) ? array_keys($state['pending']) : [];
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
     * Build the message meta payload, tucking a paused turn's raw provider blocks alongside the response meta.
     *
     * @return array<string, mixed>
     */
    protected function messageMeta(AgentResponse $response): array
    {
        $meta = (array) json_decode(json_encode($response->meta), true);

        $blocks = $response->hasPendingApprovals()
            ? $response->pausedProviderContentBlocks()
            : $this->providerContentBlocks($response);

        if (filled($blocks)) {
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
        $records = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        // A call resolved after an approval pause lands on a later row than the call, so gather every result ID across the window to keep those calls while dropping legacy dangling ones...
        $resolvedCallIds = $records
            ->flatMap(fn ($record) => collect(json_decode((string) $record->tool_results, true))->pluck('id'))
            ->filter()
            ->all();

        return $records
            ->flatMap(function ($record) use ($resolvedCallIds): array {
                $toolCalls = collect(json_decode((string) $record->tool_calls, true))->values();
                $toolResults = collect(json_decode((string) $record->tool_results, true))->values();
                $meta = (array) json_decode($record->meta ?? '[]', true);
                $providerContentBlocks = $meta['provider_content_blocks'] ?? [];
                $provider = $meta['provider'] ?? null;

                if ($record->role === 'user') {
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    return $this->reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
                }

                if ($toolResults->isNotEmpty()) {
                    $messages = [new ToolResultMessage($toolResults->map(ToolResult::fromArray(...)))];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content, providerContentBlocks: $providerContentBlocks, providerContentBlocksProvider: $provider);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content, providerContentBlocks: $providerContentBlocks, providerContentBlocksProvider: $provider)];
            })
            ->skipWhile(fn (Message $message) => $message instanceof ToolResultMessage)
            ->values();
    }

    /**
     * Rebuild the messages for a stored assistant turn that made tool calls, keeping a pause distinct from a completed turn.
     *
     * @param  Collection<int, array<string, mixed>>  $toolCalls
     * @param  Collection<int, array<string, mixed>>  $toolResults
     * @param  array<int, string>  $resolvedCallIds  Ids of calls answered anywhere in the window.
     * @return array<int, Message>
     */
    protected function reconstructToolTurn(object $record, Collection $toolCalls, Collection $toolResults, array $resolvedCallIds = []): array
    {
        $callIds = $toolCalls->pluck('id')->all();

        [$priorResults, $ownResults] = $toolResults->partition(
            fn (array $toolResult) => ! in_array($toolResult['id'], $callIds, true)
        );

        $resultsById = $ownResults->keyBy('id');

        [$resolvedCalls, $pendingCalls] = $toolCalls->partition(
            fn (array $toolCall) => filled($toolCall['id'] ?? null) && $resultsById->has($toolCall['id'])
        );

        $ownResults = $resolvedCalls->map(fn (array $toolCall) => $resultsById[$toolCall['id']])->values();
        $resolvedCalls = $resolvedCalls->values();
        $pendingCalls = $pendingCalls->values();

        $pausedCallIds = $this->pausedCallIds($record);

        $isPause = $pendingCalls->isNotEmpty()
            && $pendingCalls->every(fn (array $toolCall) => in_array($toolCall['id'], $pausedCallIds, true));

        $messages = [];

        if ($priorResults->isNotEmpty()) {
            $messages[] = new ToolResultMessage($priorResults->map(ToolResult::fromArray(...))->values());
        }

        $meta = (array) json_decode($record->meta ?? '[]', true);

        $providerContentBlocks = $meta['provider_content_blocks'] ?? [];
        $provider = $meta['provider'] ?? null;

        if ($isPause && filled($providerContentBlocks)) {
            $messages[] = new AssistantMessage($record->content, $toolCalls->map(ToolCall::fromArray(...))->values(), $providerContentBlocks, $provider);

            if ($ownResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
            }

            return $messages;
        }

        // Calls already answered this turn are replayed with their results...
        if ($resolvedCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage('', $resolvedCalls->map(ToolCall::fromArray(...))->values(), $providerContentBlocks, $provider);
            $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
        }

        $keptCalls = $pendingCalls->filter(
            fn (array $toolCall) => in_array($toolCall['id'], $pausedCallIds, true)
                || in_array($toolCall['id'], $resolvedCallIds, true)
        )->values();

        if ($keptCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage($record->content, $keptCalls->map(ToolCall::fromArray(...))->values(), $providerContentBlocks, $provider);
        } elseif (filled($record->content)) {
            $messages[] = new AssistantMessage($record->content, providerContentBlocks: $providerContentBlocks, providerContentBlocksProvider: $provider);
        }

        return $messages;
    }

    /**
     * Extract provider replay state from generated assistant messages.
     */
    protected function providerContentBlocks(AgentResponse $response): array
    {
        $providerContentBlocks = [];

        $response->messages
            ->whereInstanceOf(AssistantMessage::class)
            ->each(function (AssistantMessage $message) use (&$providerContentBlocks) {
                if (blank($message->providerContentBlocks)) {
                    return;
                }

                if (array_is_list($message->providerContentBlocks)) {
                    array_push($providerContentBlocks, ...$message->providerContentBlocks);

                    return;
                }

                $providerContentBlocks = array_merge($providerContentBlocks, $message->providerContentBlocks);
            });

        return $providerContentBlocks;
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
            $row = $this->table($this->messagesTable())
                ->where('conversation_id', $conversationId)
                ->when($participantId === null,
                    fn ($query) => $query->whereNull('participant_type')->whereNull('participant_id'),
                    fn ($query) => $query->where('participant_type', $participantType)->where('participant_id', $participantId))
                ->where('role', 'assistant')
                ->whereNotNull('approval_state')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->first(fn ($record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException('The approval results do not match a paused conversation turn.', collect());
            }

            $existing = collect(json_decode($row->tool_results, true) ?: []);

            $merged = $existing->merge(
                collect($toolResults)->reject(fn (ToolResult $result) => $existing->contains('id', $result->id))
            );

            $pending = collect(((array) json_decode($row->approval_state ?? 'null', true))['pending'] ?? [])->except($resultIds);

            // Keep the marker after resolution so the resume dedup scan stays bounded to ever-paused rows, while each call's outcome lives in the merged tool results...
            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update([
                    'tool_results' => $merged->values()->toJson(),
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
