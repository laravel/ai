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
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

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
            'status' => MessageStatus::Completed,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store the assistant turn for the given conversation, folding a resumed run into the row it paused on.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        if ($prompt->hasApprovalDecisions() && ($paused = $this->pausedRowFor($conversationId, $prompt)) !== null) {
            return $this->resumePausedRow($conversationId, $paused, $prompt, $response);
        }

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
            'meta' => json_encode($response->meta),
            'status' => $response->hasPendingApprovals() ? MessageStatus::Paused : MessageStatus::Completed,
        ]));

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store the steps a run completed before it failed, along with the error it died with.
     *
     * @param  array<int, Step>  $steps
     */
    public function storeFailedAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, array $steps, Throwable $exception): string
    {
        if ($prompt->hasApprovalDecisions() && ($paused = $this->pausedRowFor($conversationId, $prompt)) !== null) {
            return $this->failPausedRow($conversationId, $paused, $steps, $exception);
        }

        $messageId = (string) Str::uuid7();

        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $this->lastStepText($steps),
            'attachments' => '[]',
            'steps' => $this->failedStepsFor($steps)->toJson(),
            'usage' => json_encode($this->usageAcross($steps)),
            'meta' => json_encode(['provider' => $prompt->provider()->name(), 'error' => $exception->getMessage()]),
            'status' => MessageStatus::Failed,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Fail the row a resumed run paused on, keeping the turn in the single row it started as.
     *
     * @param  array<int, Step>  $steps
     */
    protected function failPausedRow(string $conversationId, object $paused, array $steps, Throwable $exception): string
    {
        $now = now();

        $text = $this->lastStepText($steps);

        $this->table($this->messagesTable())->where('id', $paused->id)->update([
            'content' => $text === '' ? $paused->content : $text,
            'steps' => $this->withoutReplayBlocks($this->decodedSteps($paused))->concat($this->failedStepsFor($steps))->toJson(),
            'usage' => json_encode(TextUsage::fromArray($this->decoded($paused->usage))->add($this->usageAcross($steps))),
            'meta' => json_encode([...$this->decoded($paused->meta), 'error' => $exception->getMessage()]),
            'status' => MessageStatus::Failed,
            'updated_at' => $now,
        ]);

        $this->forgetReplayBlocks($conversationId);

        $this->touchConversation($conversationId, $now);

        return $paused->id;
    }

    /**
     * The text the run had produced by the step it died on.
     *
     * @param  array<int, Step>  $steps
     */
    protected function lastStepText(array $steps): string
    {
        return $steps === [] ? '' : end($steps)->text;
    }

    /**
     * Serialize the steps a failed run completed, without the raw blocks no later turn can replay.
     *
     * @param  array<int, Step>  $steps
     * @return Collection<int, array<string, mixed>>
     */
    protected function failedStepsFor(array $steps): Collection
    {
        return collect($steps)->map(fn (Step $step): array => [
            'content' => $step->text,
            'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults, collect()),
            'reasoning' => $step->reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
        ])->values();
    }

    /**
     * Total the usage the steps a failed run completed reported.
     *
     * @param  array<int, Step>  $steps
     */
    protected function usageAcross(array $steps): TextUsage
    {
        return collect($steps)->reduce(fn (TextUsage $total, Step $step): TextUsage => $total->add($step->usage), new TextUsage);
    }

    /**
     * Find the row the given resume paused on, matching the turn its decisions name.
     */
    protected function pausedRowFor(string $conversationId, AgentPrompt $prompt): ?object
    {
        $decided = array_keys($prompt->approvalDecisions->all());

        $named = $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused)
            ->get()
            ->first(fn (object $record): bool => array_intersect($this->gatedCallIds($record), $decided) !== []);

        if ($named !== null) {
            return $named;
        }

        $newest = $this->assistantRows($conversationId)->first();

        return $newest === null || MessageStatus::from($newest->status) !== MessageStatus::Paused ? null : $newest;
    }

    /**
     * Append the steps a resumed run made to the row its turn paused on.
     */
    protected function resumePausedRow(string $conversationId, object $paused, AgentPrompt $prompt, AgentResponse $response): string
    {
        $steps = $this->decodedSteps($paused);

        if (($this->decoded($paused->meta)['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        if ($response->steps->isNotEmpty()) {
            $steps = $steps->concat($this->stepsFor($prompt, $response));
        }

        if (! $response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        $now = now();

        $this->table($this->messagesTable())->where('id', $paused->id)->update([
            'content' => blank($response->text) ? $paused->content : $response->text,
            'steps' => $steps->toJson(),
            'usage' => json_encode(TextUsage::fromArray($this->decoded($paused->usage))->add($response->usage)),
            'meta' => json_encode($this->mergedMeta($paused, $response)),
            'status' => $response->hasPendingApprovals() ? MessageStatus::Paused : MessageStatus::Completed,
            'updated_at' => $now,
        ]);

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId, $now);

        return $paused->id;
    }

    /**
     * Keep the citations the paused half of the turn collected, under the resuming provider and model.
     *
     * @return array<string, mixed>
     */
    protected function mergedMeta(object $paused, AgentResponse $response): array
    {
        return [
            ...$response->meta->toArray(),
            'citations' => [...$this->decoded($paused->meta)['citations'] ?? [], ...$response->meta->citations->all()],
        ];
    }

    /**
     * Serialize the turn's steps, one entry per model round-trip.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        $reasons = $this->pendingReasonsFor($response);

        if ($response->steps->isNotEmpty()) {
            return $response->steps->values()->map(fn (Step $step): array => [
                'content' => $step->text,
                'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults, $reasons),
                'reasoning' => $step->reasoning,
                'replay_blocks' => $response->hasPendingApprovals() ? $step->replayBlocks : [],
                'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
            ]);
        }

        // A resume that ran no step only carries the approval results storeApprovalResults() already wrote to the paused row...
        return collect([[
            'content' => $response->text,
            'tool_calls' => $this->toolCallsFor(
                $response->toolCalls->all(),
                $prompt->hasApprovalDecisions() ? [] : $response->toolResults->all(),
                $reasons,
            ),
            'reasoning' => $response->reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]);
    }

    /**
     * Pair a step's tool calls with the results they were answered by, marking those awaiting approval with their reason.
     *
     * @param  iterable<int, ToolCall>  $toolCalls
     * @param  iterable<int, ToolResult>  $toolResults
     * @param  Collection<string, string|null>  $reasons
     * @return list<array<string, mixed>>
     */
    protected function toolCallsFor(iterable $toolCalls, iterable $toolResults, Collection $reasons): array
    {
        $results = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

        return collect($toolCalls)->map(function (ToolCall $toolCall) use ($results, $reasons): array {
            $result = $results->get($toolCall->id);

            $stored = Arr::except($toolCall->toArray(), ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);

            if ($toolCall->thoughtSignature === null) {
                unset($stored['thought_signature']);
            }

            return [
                ...$stored,
                ...$reasons->has($toolCall->id) ? ['approval_reason' => $reasons[$toolCall->id]] : [],
                ...$result === null ? [] : Arr::only($result->toArray(), ['result', 'denied', 'failed']),
            ];
        })->values()->all();
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn resumed from.
     */
    protected function forgetReplayBlocks(string $conversationId): void
    {
        $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('status', MessageStatus::Paused)
            ->get(['id', 'steps'])
            ->each(function (object $record): void {
                $steps = $this->decodedSteps($record);

                if ($steps->every(fn (array $step): bool => $step['replay_blocks'] === [])) {
                    return;
                }

                $this->table($this->messagesTable())->where('id', $record->id)->update([
                    'steps' => $this->withoutReplayBlocks($steps)->toJson(),
                ]);
            });
    }

    /**
     * Drop the raw provider blocks from the given steps.
     *
     * @param  Collection<int, array<string, mixed>>  $steps
     * @return Collection<int, array<string, mixed>>
     */
    protected function withoutReplayBlocks(Collection $steps): Collection
    {
        return $steps->map(fn (array $step): array => [...$step, 'replay_blocks' => []]);
    }

    /**
     * The reasons a response paused on, keyed by tool call ID.
     *
     * @return Collection<string, string|null>
     */
    protected function pendingReasonsFor(AgentResponse $response): Collection
    {
        return $response->pendingApprovals->mapWithKeys(fn (PendingApproval $approval) => [$approval->id => $approval->reason]);
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
     * Get the tool-call IDs a stored row is still awaiting a decision on.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        return $this->pendingApprovalsIn($record)->map(fn (PendingApproval $approval) => $approval->id)->all();
    }

    /**
     * Get the IDs of a stored row's tool calls that were gated behind an approval.
     *
     * @return array<int, string>
     */
    protected function gatedCallIds(object $record): array
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter(fn (array $toolCall): bool => array_key_exists('approval_reason', $toolCall))
            ->pluck('id')
            ->all();
    }

    /**
     * Rebuild the approvals a stored row is still awaiting a decision on.
     *
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsIn(object $record): Collection
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter(PendingApproval::isPending(...))
            ->map(fn (array $toolCall) => new PendingApproval(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'],
                $toolCall['approval_reason'],
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

        return $records->flatMap(fn (object $record): array => $record->role === 'user'
            ? [$this->userMessageFrom($record)]
            : $this->assistantTurnFrom($record));
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
    protected function assistantTurnFrom(object $record): array
    {
        $pending = $this->pausedCallIds($record);
        $provider = $this->decoded($record->meta)['provider'] ?? null;
        $failed = MessageStatus::from($record->status) === MessageStatus::Failed;

        return $this->decodedSteps($record)->flatMap(function (array $step) use ($pending, $provider, $failed): array {
            $content = $step['content'];

            $replayed = collect($step['tool_calls'])
                ->filter(fn (array $toolCall) => $failed || PendingApproval::isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true))
                ->values();

            $toolCalls = $replayed->map(ToolCall::fromArray(...));

            // A failed turn died with calls it never answered, so the model is told which one it may have run rather than shown a call with no result...
            $toolResults = $replayed
                ->filter(fn (array $toolCall) => PendingApproval::isAnswered($toolCall) || $failed)
                ->map(fn (array $toolCall) => PendingApproval::isAnswered($toolCall) ? ToolResult::fromArray($toolCall) : $this->interruptedResultFor($toolCall))
                ->values();

            // Raw blocks still name a dropped call, so a step missing one rebuilds generically rather than replaying a call no result answers...
            $replayBlocks = $replayed->count() === count($step['tool_calls']) ? $step['replay_blocks'] : [];

            $isBlank = $content === '' && $toolCalls->isEmpty() && $replayBlocks === [];

            $messages = $isBlank ? [] : [new AssistantMessage($content, $toolCalls, $replayBlocks, $provider)];

            if ($toolResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($toolResults);
            }

            return $messages;
        })->all();
    }

    /**
     * The result a failed turn's unanswered call replays with.
     *
     * @param  array<string, mixed>  $toolCall
     */
    protected function interruptedResultFor(array $toolCall): ToolResult
    {
        return new ToolResult(
            $toolCall['id'],
            $toolCall['name'],
            $toolCall['arguments'] ?? [],
            'This tool call was interrupted before a result was recorded, so it may or may not have run.',
        );
    }

    /**
     * Decode a stored row's steps.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function decodedSteps(object $record): Collection
    {
        return collect($this->decoded($record->steps))->map(fn (array $step): array => [
            'content' => (string) ($step['content'] ?? ''),
            'tool_calls' => array_values($step['tool_calls'] ?? []),
            'reasoning' => (string) ($step['reasoning'] ?? ''),
            'replay_blocks' => $step['replay_blocks'] ?? [],
            'provider_tool_calls' => array_values($step['provider_tool_calls'] ?? []),
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
            ->first(['role', 'steps', 'status']);

        return $newest === null || $newest->role !== 'assistant' || MessageStatus::from($newest->status) !== MessageStatus::Paused
            ? []
            : $this->pendingApprovalsIn($newest)->all();
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
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        DB::connection($this->connection)->transaction(function () use ($conversationId, $toolResults, $resultIds) {
            $paused = $this->assistantRows($conversationId)
                ->where('status', MessageStatus::Paused)
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

                    return $result === null || PendingApproval::isAnswered($toolCall)
                        ? $toolCall
                        // Arguments come along because an edited approval runs the tool with different ones than the call asked for...
                        : [...$toolCall, ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed'])];
                }, $step['tool_calls']);

                return $step;
            });

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update(['steps' => $steps->toJson(), 'updated_at' => now()]);
        });
    }

    /**
     * Query the conversation's assistant rows, newest first.
     */
    protected function assistantRows(string $conversationId): Builder
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id');
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
