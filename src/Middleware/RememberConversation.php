<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Throwable;

class RememberConversation
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ConversationStore $store,
        protected TextProvider $provider,
    ) {}

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        $conversation = $agent->currentConversation();

        if ($conversation !== null && $prompt->resume !== null && $this->store instanceof VerifiesConversationOwnership
            && ! $this->store->conversationBelongsTo($conversation, $agent->conversationParticipant()?->id)) {
            throw new AuthorizationException('This conversation does not belong to the current participant.');
        }

        $lock = null;

        if ($conversation !== null && $this->requiresPauseLock($prompt, $agent)) {
            $lock = Cache::lock('ai:approval-resume:'.$conversation, 300);

            if (! $lock->get()) {
                throw new ApprovalMismatchException(
                    'The pending tool calls have already been resumed or are being resumed.',
                    $this->pendingApprovalsFor($agent),
                );
            }
        }

        try {
            return $this->remember($prompt, $next, $lock);
        } catch (Throwable $exception) {
            $lock?->release();

            throw $exception;
        }
    }

    /**
     * Determine whether the turn must hold the conversation's pause lock.
     */
    protected function requiresPauseLock(AgentPrompt $prompt, Agent $agent): bool
    {
        if ($prompt->resume !== null) {
            return true;
        }

        return $agent instanceof HasTools && $this->pendingApprovalsFor($agent)->isNotEmpty();
    }

    /**
     * Run the prompt and persist the conversation once it completes.
     */
    protected function remember(AgentPrompt $prompt, Closure $next, ?Lock $lock = null)
    {
        $pending = $next($prompt);

        if ($lock !== null && $pending instanceof StreamableAgentResponse) {
            $pending->finally(fn () => $lock->release());

            $lock = null;
        }

        return $pending->then(function ($response) use ($prompt, $lock) {
            /** @var Agent&RemembersConversations $agent */
            $agent = $prompt->agent;

            // Create conversation if necessary...
            if (! $agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $agent->conversationParticipant()?->id,
                    $this->generateTitle($prompt->resume !== null ? 'Tool approval' : $prompt->prompt)
                );

                $agent->continue(
                    $conversationId,
                    $agent->conversationParticipant()
                );
            }

            // Record user message...
            if ($prompt->resume === null) {
                $this->store->storeUserMessage(
                    $agent->currentConversation(),
                    $agent->conversationParticipant()?->id,
                    $prompt
                );
            }

            // Record assistant message...
            $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $agent->conversationParticipant()?->id,
                $prompt,
                $response
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $agent->conversationParticipant(),
            );

            $lock?->release();
        });
    }

    /**
     * Get the pending approvals awaiting a decision in the agent's conversation.
     *
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsFor(Agent $agent)
    {
        $messages = $agent instanceof Conversational ? [...$agent->messages()] : [];

        $tools = $agent instanceof HasTools
            ? array_values(array_filter([...$agent->tools()], fn ($tool) => $tool instanceof Tool))
            : [];

        return $this->provider->textGenerationLoop()->pendingApprovals($messages, $tools);
    }

    /**
     * Generate a title for the conversation.
     */
    protected function generateTitle(string $prompt): string
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return Str::limit($prompt, 50, preserveWords: true);
        }

        try {
            $response = $this->provider->textGenerationLoop()->generate(
                $this->provider,
                $this->provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            return Str::limit($response->text, 100);
        } catch (Throwable) {
            return Str::limit($prompt, 100, preserveWords: true);
        }
    }
}
