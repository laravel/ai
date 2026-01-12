<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;

class RememberConversation
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        return $next($prompt)->then(function ($response) use ($prompt) {
            $agent = $prompt->agent;

            // Create conversation if necessary...
            if (! $agent->currentConversation()) {
                $conversationId = (string) Str::uuid7();

                DB::table('agent_conversations')->insert([
                    'id' => $conversationId,
                    'user_id' => $agent->conversationParticipant()->id,
                    'title' => Str::limit($prompt->prompt, 100),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $agent->continue(
                    $conversationId,
                    $agent->conversationParticipant()
                );
            }

            // Record user message...
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $agent->currentConversation(),
                'user_id' => $agent->conversationParticipant()->id,
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

            // Record assistant message...
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $agent->currentConversation(),
                'user_id' => $agent->conversationParticipant()->id,
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

            $response->withinConversation(
                $agent->currentConversation(),
                $agent->conversationParticipant(),
            );
        });
    }
}
