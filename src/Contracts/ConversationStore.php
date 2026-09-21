<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

interface ConversationStore
{
    /**
     * Get the participant's most recent conversation ID with the given agent.
     *
     * @param  class-string<Agent>  $agent
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string;

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string;

    /**
     * Update the title of the given conversation.
     */
    public function updateConversationTitle(string $conversationId, string $title): void;

    /**
     * Store a new user message for the given conversation and return its ID.
     *
     * @param  class-string<Agent>  $agent
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string;

    /**
     * Open an assistant turn that is about to run and return its message ID.
     *
     * @param  class-string<Agent>  $agent
     */
    public function startAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent): string;

    /**
     * Reopen the paused assistant turn the given decisions name, whichever participant paused it, or null when nothing is paused.
     *
     * @param  array<int, string>  $decided
     */
    public function resumeAssistantMessage(string $conversationId, string $provider, array $decided): ?string;

    /**
     * Append a step the model just produced to an open assistant turn, before its tools run.
     */
    public function storeStep(string $messageId, Step $step): void;

    /**
     * Record the results of tool calls an open assistant turn is waiting on, whether they ran live or after approval.
     *
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeToolResults(string $messageId, array $toolResults): void;

    /**
     * Close an open assistant turn with the response the run returned.
     */
    public function completeAssistantMessage(string $messageId, AgentPrompt $prompt, AgentResponse $response): void;

    /**
     * Record the approvals an open assistant turn paused on, before the pause reaches the caller.
     *
     * @param  array<int, PendingApproval>  $approvals
     */
    public function storePendingApprovals(string $messageId, array $approvals): void;

    /**
     * Record the error a run failed with on its turn.
     */
    public function failAssistantMessage(string $messageId, Throwable $exception): void;

    /**
     * Get the latest messages for the given conversation, optionally only those stored before the given message.
     *
     * Message IDs order the conversation, so a store must issue them in ascending order, as UUIDv7 does.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit, ?string $before = null): Collection;
}
