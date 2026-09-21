<?php

namespace Laravel\Ai\Storage;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\ConnectionInterface;
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
        return $this->messages()
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
        $conversation = $this->conversations()
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

        $this->conversations()->insert([
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

        $this->messages()->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $agent,
            'role' => 'user',
            'content' => $message->content,
            'attachments' => $message->attachments->toJson(),
            'status' => MessageStatus::Completed,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Update the title of the given conversation.
     */
    public function updateConversationTitle(string $conversationId, string $title): void
    {
        $this->conversations()
            ->where('id', $conversationId)
            ->update(['title' => $title, 'updated_at' => now()]);
    }

    /**
     * Open an assistant turn that is about to run and return its message ID.
     *
     * @param  class-string<Agent>  $agent
     */
    public function startAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent): string
    {
        $messageId = (string) Str::uuid7();

        $now = now();

        $this->messages()->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $agent,
            'role' => 'assistant',
            'content' => '',
            'status' => MessageStatus::Started,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Reopen the paused assistant turn the given decisions name so a resume on the given provider continues on it, or null when nothing is paused.
     *
     * @param  array<int, string>  $decided
     */
    public function resumeAssistantMessage(string $conversationId, string $provider, array $decided): ?string
    {
        return $this->connection()->transaction(function () use ($conversationId, $provider, $decided): ?string {
            $paused = $this->pausedRowFor($conversationId, $decided);

            if ($paused === null || $this->pausedCallIds($paused) === []) {
                return null;
            }

            $steps = ($this->decoded($paused->meta)['provider'] ?? null) === $provider
                ? []
                : ['steps' => $this->withoutReplayBlocks($this->decodedSteps($paused))->toJson()];

            // A turn already running elsewhere is left alone, so two resumes of one pause cannot both execute its tools...
            $claimed = $this->messages()
                ->where('id', $paused->id)
                ->whereIn('status', [MessageStatus::Paused, MessageStatus::Failed])
                ->update([...$steps, 'status' => MessageStatus::Started, 'updated_at' => now()]);

            return $claimed === 0 ? null : $paused->id;
        });
    }

    /**
     * Append a step the model just produced to an open assistant turn, before its tools run.
     */
    public function storeStep(string $messageId, Step $step): void
    {
        $this->reviseOpenSteps($messageId, fn (Collection $steps): Collection => $steps->push($this->serializedStep($step)));
    }

    /**
     * Record the results of tool calls an open assistant turn is waiting on, whether they ran live or after approval.
     *
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeToolResults(string $messageId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resolved = $this->keyedResults($toolResults);

        $this->reviseOpenSteps($messageId, fn (Collection $steps): Collection => $steps->map(fn (array $step): array => [
            ...$step,
            'tool_calls' => array_map(fn (array $toolCall): array => $this->withResult($toolCall, $resolved), $step['tool_calls']),
        ]));
    }

    /**
     * Rewrite an open assistant turn's steps under a lock, leaving the turn open.
     *
     * @param  callable(Collection<int, array<string, mixed>>): Collection<int, array<string, mixed>>  $revise
     */
    protected function reviseOpenSteps(string $messageId, callable $revise): void
    {
        $this->connection()->transaction(function () use ($messageId, $revise): void {
            $row = $this->lockedMessage($messageId);

            $this->messages()->where('id', $messageId)->update([
                'steps' => $revise($this->decodedSteps($row))->toJson(),
                'status' => MessageStatus::Started,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Fold a tool call's result onto the stored call, leaving one that already has an answer alone.
     *
     * @param  array<string, mixed>  $toolCall
     * @param  Collection<string, ToolResult>  $results
     * @return array<string, mixed>
     */
    protected function withResult(array $toolCall, Collection $results): array
    {
        $result = $results->get($toolCall['id'] ?? '');

        if ($result === null || PendingApproval::isAnswered($toolCall)) {
            return $toolCall;
        }

        // Arguments come along because an edited approval runs the tool with different ones than the call asked for...
        return [...$toolCall, ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed'])];
    }

    /**
     * Close an open assistant turn with the response the run returned.
     */
    public function completeAssistantMessage(string $messageId, AgentPrompt $prompt, AgentResponse $response): void
    {
        $now = now();

        $this->connection()->transaction(function () use ($messageId, $response, $now): void {
            $row = $this->lockedMessage($messageId);

            $recorded = $this->decodedSteps($row);

            // A turn nothing recorded as it ran, such as one remembered only once it paused, is written from the response in full...
            $steps = $this->withApprovalReasons($recorded->isEmpty() ? $this->stepsFor($response) : $recorded, $this->pendingReasonsFor($response));

            if (! $response->hasPendingApprovals()) {
                $steps = $this->withoutReplayBlocks($steps);
            }

            $this->messages()->where('id', $messageId)->update([
                'content' => blank($response->text) ? $row->content : $response->text,
                'steps' => $steps->toJson(),
                'usage' => json_encode(TextUsage::fromArray($this->decoded($row->usage))->add($response->usage)),
                'meta' => json_encode($this->mergedMeta($row, $response)),
                'status' => $response->hasPendingApprovals() ? MessageStatus::Paused : MessageStatus::Completed,
                'updated_at' => $now,
            ]);

            if (! $response->hasPendingApprovals()) {
                $this->forgetReplayBlocks($row->conversation_id);
            }

            $this->touchConversation($row->conversation_id, $now);
        });
    }

    /**
     * Find the row the given resume paused on, matching the turn its decisions name.
     *
     * @param  array<int, string>  $decided
     */
    protected function pausedRowFor(string $conversationId, array $decided): ?object
    {
        $named = $this->assistantRows($conversationId)
            ->where('status', '!=', MessageStatus::Completed)
            ->get()
            ->first(fn (object $record): bool => array_intersect($this->gatedCallIds($record), $decided) !== []);

        if ($named !== null) {
            return $named;
        }

        $newest = $this->assistantRows($conversationId)->first();

        if ($newest === null || MessageStatus::from($newest->status) === MessageStatus::Completed) {
            return null;
        }

        return $this->pendingApprovalsIn($newest)->isNotEmpty() ? $newest : null;
    }

    /**
     * Record the approvals an open assistant turn paused on, before the pause reaches the caller.
     *
     * @param  array<int, PendingApproval>  $approvals
     */
    public function storePendingApprovals(string $messageId, array $approvals): void
    {
        if ($approvals === []) {
            return;
        }

        $reasons = collect($approvals)->mapWithKeys(fn (PendingApproval $approval): array => [$approval->id => $approval->reason]);

        $this->connection()->transaction(function () use ($messageId, $reasons): void {
            $row = $this->lockedMessage($messageId);

            $this->messages()->where('id', $messageId)->update([
                'steps' => $this->withApprovalReasons($this->decodedSteps($row), $reasons)->toJson(),
                'status' => MessageStatus::Paused,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Record the error a run failed with on its turn.
     */
    public function failAssistantMessage(string $messageId, Throwable $exception): void
    {
        $this->connection()->transaction(function () use ($messageId, $exception): void {
            $row = $this->lockedMessage($messageId);

            $this->messages()->where('id', $messageId)->update([
                'meta' => json_encode([...$this->decoded($row->meta), 'error' => $exception->getMessage()]),
                'status' => MessageStatus::Failed,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Load a message row under a write lock.
     */
    protected function lockedMessage(string $messageId): object
    {
        $row = $this->messages()->where('id', $messageId)->lockForUpdate()->first();

        if ($row === null) {
            throw new InvalidArgumentException("Conversation message [{$messageId}] does not exist.");
        }

        return $row;
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
     * Serialize the turn's steps, one entry per model round-trip, with each result on the call that made it.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentResponse $response): Collection
    {
        if ($response->steps->isNotEmpty()) {
            return $response->steps->values()->map(fn (Step $step): array => $this->serializedStep(
                $step, $step->toolResults, $response->hasPendingApprovals() ? $step->replayBlocks : [],
            ));
        }

        return collect([[
            'content' => $response->text,
            'tool_calls' => $this->toolCallsFor($response->toolCalls->all(), $response->toolResults->all()),
            'reasoning' => $response->reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]);
    }

    /**
     * Serialize one model round-trip into its stored shape.
     *
     * @param  iterable<int, ToolResult>  $toolResults
     * @param  array<int, array<string, mixed>>|null  $replayBlocks
     * @return array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}
     */
    protected function serializedStep(Step $step, iterable $toolResults = [], ?array $replayBlocks = null): array
    {
        return [
            'content' => $step->text,
            'tool_calls' => $this->toolCallsFor($step->toolCalls, $toolResults),
            'reasoning' => $step->reasoning,
            'replay_blocks' => $replayBlocks ?? $step->replayBlocks,
            'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
        ];
    }

    /**
     * Mark the tool calls a turn paused on with the reason they await, so the steps carry the pause themselves.
     *
     * @param  Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>  $steps
     * @param  Collection<string, ?string>  $reasons
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function withApprovalReasons(Collection $steps, Collection $reasons): Collection
    {
        if ($reasons->isEmpty()) {
            return $steps;
        }

        return $steps->map(function (array $step) use ($reasons): array {
            $step['tool_calls'] = array_map(
                fn (array $toolCall): array => $reasons->has($toolCall['id'] ?? '') ? [...$toolCall, 'approval_reason' => $reasons[$toolCall['id']]] : $toolCall,
                $step['tool_calls'],
            );

            return $step;
        });
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
        $results = $this->keyedResults($toolResults);

        return collect($toolCalls)->map(function (ToolCall $toolCall) use ($results): array {
            $result = $results->get($toolCall->id);

            $stored = Arr::except($toolCall->toArray(), ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);

            if ($toolCall->thoughtSignature === null) {
                unset($stored['thought_signature']);
            }

            return [
                ...$stored,
                ...$result === null ? [] : Arr::only($result->toArray(), ['result', 'denied', 'failed']),
            ];
        })->values()->all();
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn resumed from.
     */
    protected function forgetReplayBlocks(string $conversationId): void
    {
        $this->messages()
            ->where('conversation_id', $conversationId)
            ->where('status', '!=', MessageStatus::Completed)
            ->get(['id', 'steps'])
            ->each(function (object $record): void {
                $steps = $this->decodedSteps($record);

                if ($steps->every(fn (array $step): bool => $step['replay_blocks'] === [])) {
                    return;
                }

                $this->messages()->where('id', $record->id)->update([
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
        $this->conversations()
            ->where('id', $conversationId)
            ->update(['updated_at' => $timestamp]);
    }

    /**
     * Key the given tool results by the ID of the call they answer.
     *
     * @param  iterable<int, ToolResult>  $toolResults
     * @return Collection<string, ToolResult>
     */
    protected function keyedResults(iterable $toolResults): Collection
    {
        return collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);
    }

    /**
     * Build the message row attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        return [
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            ...$attributes,
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Get the latest messages for the given conversation, optionally only those stored before the given message.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit, ?string $before = null): Collection
    {
        $records = $this->messages()
            ->useWritePdo()
            ->where('conversation_id', $conversationId)
            ->when($before !== null, fn (Builder $query): Builder => $query->where('id', '<', $before))
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
        $interrupted = MessageStatus::from($record->status)->isInterrupted();

        return $this->decodedSteps($record)->flatMap(function (array $step) use ($pending, $provider, $interrupted): array {
            $content = $step['content'];

            $replayed = collect($step['tool_calls'])
                ->filter(fn (array $toolCall) => $interrupted || PendingApproval::isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true))
                ->values();

            $toolCalls = $replayed->map(ToolCall::fromArray(...));

            // A turn that never completed was killed mid-run, so the model is told which call it may have executed rather than shown nothing...
            $toolResults = $replayed
                ->filter(fn (array $toolCall) => PendingApproval::isAnswered($toolCall) || ($interrupted && ! in_array($toolCall['id'] ?? null, $pending, true)))
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
     * The result a killed turn's unanswered call replays with.
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
            $toolCall['result_id'] ?? null,
            failed: true,
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
        return $this->messages()
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
        $newest = $this->messages()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first(['role', 'steps', 'status']);

        return $newest === null || $newest->role !== 'assistant' || MessageStatus::from($newest->status) === MessageStatus::Completed
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
     * Query the conversation's assistant rows, newest first.
     */
    protected function assistantRows(string $conversationId): Builder
    {
        return $this->messages()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id');
    }

    /**
     * Get a query builder for the conversation messages table.
     */
    protected function messages(): Builder
    {
        return $this->connection()->table($this->messagesTable());
    }

    /**
     * Get a query builder for the conversations table.
     */
    protected function conversations(): Builder
    {
        return $this->connection()->table($this->conversationsTable());
    }

    /**
     * Get the database connection the conversations are stored on.
     */
    protected function connection(): ConnectionInterface
    {
        return DB::connection($this->connection);
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
