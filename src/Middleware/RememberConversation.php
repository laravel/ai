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
                    'user_id' => $user->id,
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
                'conversation_id' => $agent->currentConversation(),
                'user_id' => $agent->conversationParticipant()->id,
                'role' => 'user',
                'content' => $prompt->prompt,
                'tool_calls' => '',
                'tool_results' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Record assistant message...
            DB::table('agent_conversation_messages')->insert([
                'conversation_id' => $agent->currentConversation(),
                'user_id' => $agent->conversationParticipant(),
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => json_encode($response->toolCalls),
                'tool_results' => json_encode($response->toolResults),
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
