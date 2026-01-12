<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;

class RecordConversation
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $agent = $prompt->agent;
        $user = $agent->getConversationUser();
        $uuid = $agent->getConversationUuid();

        return $next($prompt)->then(function ($response) use ($agent, $user, $uuid, $prompt) {
            // Get existing conversation or create new one
            if ($uuid) {
                $conversation = DB::table('agent_conversations')
                    ->where('uuid', $uuid)
                    ->first();

                $conversationId = $conversation->id;
            } else {
                $uuid = (string) Str::uuid7();

                $conversationId = DB::table('agent_conversations')->insertGetId([
                    'uuid' => $uuid,
                    'user_id' => $user->id,
                    'title' => Str::limit($prompt->prompt, 100),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $agent->setConversationUuid($uuid);
            }

            // Record user message
            DB::table('agent_conversation_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $prompt->prompt,
                'tool_calls' => '',
                'tool_results' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Record assistant message
            DB::table('agent_conversation_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => json_encode($response->toolCalls),
                'tool_results' => json_encode($response->toolResults),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response->withinConversation($agent->getConversationUuid());
        });
    }
}
