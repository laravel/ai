<?php

namespace Tests\Fixtures\Migrations;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Migrations\AiMigration;

class BackfillConversationSteps extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->nullable();
        });

        $this->query($table)->where('role', 'user')->update(['steps' => '[]']);
        $this->query($table)->update(['status' => MessageStatus::Completed]);

        $this->query($table)
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) use ($table) {
                foreach ($conversations as $conversation) {
                    $this->backfillConversation($table, $conversation->conversation_id);
                }
            });

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable(false)->change();
            $blueprint->string('status', 25)->nullable(false)->change();
            $blueprint->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
        });
    }

    /**
     * Rewrite every assistant row of one conversation as steps, each result landing on the invocation that made its call.
     */
    protected function backfillConversation(string $table, string $conversationId): void
    {
        $rows = $this->query($table)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // Results are gathered across the conversation because an approval resolved on a later request used to be recorded on that request's row...
        $results = [];
        $pending = [];

        foreach ($rows as $row) {
            foreach ($this->decoded($row->tool_results) as $result) {
                if (isset($result['id'])) {
                    $results[$result['id']] ??= $result;
                }
            }

            $pending = [...$pending, ...$this->decoded($row->approval_state)['pending'] ?? []];
        }

        foreach ($rows as $row) {
            [$steps, $meta] = $this->stepsFrom($row);

            $steps = array_map(function (array $step) use ($results, $pending): array {
                $toolCalls = [];

                foreach ($step['tool_calls'] as $toolCall) {
                    $result = $results[$toolCall['id'] ?? ''] ?? null;

                    $awaiting = array_key_exists($toolCall['id'] ?? '', $pending);

                    if ($result === null && ! $awaiting) {
                        continue;
                    }

                    $toolCalls[] = [
                        ...$toolCall,
                        ...$awaiting ? ['approval_reason' => $pending[$toolCall['id']]] : [],
                        ...$result === null ? [] : [
                            'result' => $result['result'] ?? null,
                            ...array_filter(['denied' => $result['denied'] ?? false, 'failed' => $result['failed'] ?? false]),
                        ],
                    ];
                }

                $step['tool_calls'] = $toolCalls;

                return $step;
            }, $steps);

            $this->query($table)->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
                'status' => blank($this->decoded($row->approval_state)['pending'] ?? []) ? MessageStatus::Completed : MessageStatus::Paused,
            ]);
        }
    }

    /**
     * Split a flat assistant row into steps of unanswered tool calls, moving any replay and reasoning state out of its meta.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function stepsFrom(object $row): array
    {
        $meta = $this->decoded($row->meta);
        $calls = array_values($this->decoded($row->tool_calls));

        $providerSteps = $meta['provider_steps'] ?? null;

        if (is_array($providerSteps) && $providerSteps !== []) {
            $steps = [];

            foreach ($providerSteps as $providerStep) {
                $ids = $providerStep['tool_call_ids'] ?? [];

                $steps[] = [
                    'content' => '',
                    'tool_calls' => array_values(array_filter($calls, fn (array $call) => in_array($call['id'] ?? null, $ids, true))),
                    'reasoning' => '',
                    'replay_blocks' => $providerStep['blocks'] ?? [],
                ];
            }
        } else {
            $steps = [[
                'content' => '',
                'tool_calls' => $calls,
                'reasoning' => '',
                'replay_blocks' => $meta['provider_content_blocks'] ?? [],
            ]];

            // A completed turn's text was produced after its results, so it replays as a step of its own...
            if ($calls !== [] && $row->approval_state === null && (string) $row->content !== '') {
                $steps[] = ['content' => '', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => []];
            }
        }

        $steps[array_key_last($steps)]['content'] = (string) $row->content;
        $steps[array_key_last($steps)]['reasoning'] = (string) ($meta['reasoning'] ?? '');

        unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

        return [$steps, $meta];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    protected function query(string $table): Builder
    {
        return DB::connection($this->getConnection())->table($table);
    }
}
