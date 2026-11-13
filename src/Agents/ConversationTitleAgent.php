<?php

namespace Laravel\Ai\Agents;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
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
    public static function titleFor(string $message, TextProvider|Lab|string|null $provider = null, ?string $parentInvocationId = null): string
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return Str::limit($message, 50, preserveWords: true);
        }

        $provider = $provider instanceof TextProvider
            ? $provider
            : Ai::textProvider($provider instanceof Lab ? $provider->value : $provider);

        if (self::isFaked()) {
            $provider = (clone $provider)->useTextGateway(Ai::fakeGatewayFor(new self));
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
            return self::fallbackTitleFor($message);
        }
    }

    /**
     * Get the title used when none can be generated for the given message.
     */
    public static function fallbackTitleFor(string $message): string
    {
        return Str::limit($message, 100, preserveWords: true);
    }
}
