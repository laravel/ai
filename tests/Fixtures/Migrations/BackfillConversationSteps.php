<?php

namespace Tests\Fixtures\Migrations;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * The canonical copy of the UPGRADE.md migration that converts flat assistant rows into steps.
 */
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
        });

        $this->query($table)->where('role', 'user')->update(['steps' => '[]']);

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
            $blueprint->dropColumn(['tool_calls', 'tool_results']);
        });
    }

    /**
     * Rewrite every assistant row of one conversation as steps, each result landing on the step that made its call.
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

            $pending = [...$pending, ...array_keys($this->decoded($row->approval_state)['pending'] ?? [])];
        }

        foreach ($rows as $row) {
            [$steps, $meta] = $this->stepsFrom($row);

            $steps = array_map(function (array $step) use ($results, $pending): array {
                $step['tool_results'] = array_values(array_filter(array_map(
                    fn (array $call) => $results[$call['id'] ?? ''] ?? null,
                    $step['tool_calls'],
                )));

                $answered = array_column($step['tool_results'], 'id');

                $step['tool_calls'] = array_values(array_filter(
                    $step['tool_calls'],
                    fn (array $call) => in_array($call['id'] ?? null, $answered, true) || in_array($call['id'] ?? null, $pending, true),
                ));

                return $step;
            }, $steps);

            $this->query($table)->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * Split a flat assistant row into steps without results, moving any replay state out of its meta.
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
                    'tool_calls' => array_values(array_filter($calls, fn (array $call) => in_array($call['id'] ?? null, $ids, true))),
                    'tool_results' => [],
                    'provider_blocks' => $providerStep['blocks'] ?? [],
                ];
            }
        } else {
            $steps = [[
                'tool_calls' => $calls,
                'tool_results' => [],
                'provider_blocks' => $meta['provider_content_blocks'] ?? [],
            ]];
        }

        unset($meta['provider_steps'], $meta['provider_content_blocks']);

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
