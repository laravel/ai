<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;

interface ConversationStore
{
    /**
     * Get the participant's most recent conversation ID with the given agent.
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string;

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string;

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string;

    /**
     * Store a new assistant message for the given conversation, or null when nothing was stored.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param  array<int, ToolResult>  $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void;
}
