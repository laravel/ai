<?php

namespace Laravel\Ai\Agents;

use Illuminate\Support\Str;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

#[UseCheapestModel]
final class ConversationTitleAgent implements Agent
{
    use Promptable;

    /**
     * Create a new agent instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.';
    }

    /**
     * Generate a title for a conversation that starts with the given message, or shorten the message when that fails.
     */
    public static function titleFor(string $message, TextProvider $provider, ?string $parentInvocationId = null): string
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return Str::limit($message, 50, preserveWords: true);
        }

        try {
            $response = $provider->prompt(new AgentPrompt(
                new self,
                Str::limit($message, 500),
                [],
                $provider,
                $provider->cheapestTextModel(),
                parentInvocationId: $parentInvocationId,
            ));

            return Str::limit($response->text, 100);
        } catch (Throwable) {
            return Str::limit($message, 100, preserveWords: true);
        }
    }
}
