<?php

namespace Laravel\Ai\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class DatabaseConversationStore implements ConversationStore
{
    /**
     * Get the most recent conversation ID for a given user.
     */
    public function latestConversationId(string|int $userId): ?string
    {
        return DB::table('agent_conversations')
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

        DB::table('agent_conversations')->insert([
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

        DB::table('agent_conversation_messages')->insert([
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

        DB::table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls),
            'tool_results' => json_encode($response->toolResults),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
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
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record) {
                $toolCalls = collect(json_decode($record->tool_calls, true));
                $toolResults = collect(json_decode($record->tool_results, true));

                if ($record->role === 'user') {
                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $reconstructedToolCalls = $toolCalls->map(fn ($toolCall) => new ToolCall(
                        id: $toolCall['id'],
                        name: $toolCall['name'],
                        arguments: $toolCall['arguments'],
                        resultId: $toolCall['result_id'] ?? null,
                        reasoningId: $toolCall['reasoning_id'] ?? null,
                        reasoningSummary: $toolCall['reasoning_summary'] ?? null,
                    ));

                    $reconstructedToolResults = $toolResults->map(fn ($toolResult) => new ToolResult(
                        id: $toolResult['id'],
                        name: $toolResult['name'],
                        arguments: $toolResult['arguments'],
                        result: $toolResult['result'],
                        resultId: $toolResult['result_id'] ?? null,
                    ));

                    // Index tool results by call_id for fast matching.
                    $resultsByCallId = $reconstructedToolResults->keyBy(fn (ToolResult $r) => $r->resultId);

                    // Group tool calls by reasoning_id, preserving insertion order.
                    // OpenAI requires each reasoning block to appear immediately before
                    // its associated function_calls, with tool results interleaved between
                    // rounds. Flattening all calls into one AssistantMessage breaks this.
                    $groups = [];
                    foreach ($reconstructedToolCalls as $tc) {
                        $groups[$tc->reasoningId ?? '__no_reasoning__'][] = $tc;
                    }

                    $messages = [];

                    foreach ($groups as $groupTcs) {
                        $groupCollection = collect($groupTcs);

                        $messages[] = new AssistantMessage('', $groupCollection);

                        $groupResults = $groupCollection
                            ->pluck('resultId')
                            ->filter()
                            ->map(fn ($callId) => $resultsByCallId->get($callId))
                            ->filter()
                            ->values();

                        if ($groupResults->isNotEmpty()) {
                            $messages[] = new ToolResultMessage($groupResults);
                        }
                    }

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
    }
}
