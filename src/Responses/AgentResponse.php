<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class AgentResponse extends TextResponse
{
    public string $invocationId;

    public ?string $conversationId = null;

    public ?object $conversationUser = null;

    public ?string $userMessageId = null;

    public ?string $assistantMessageId = null;

    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Create a fake response that is waiting for approval.
     *
     * @param  array<int, PendingApproval>|Collection<int, PendingApproval>  $pendingApprovals
     */
    public static function fakeWithPendingApprovals(array|Collection $pendingApprovals): self
    {
        return (new self('fake-invocation', '', new Usage, new Meta))
            ->withPendingApprovals(Collection::make($pendingApprovals));
    }

    /**
     * Set the conversation UUID and participant for this response.
     */
    public function withinConversation(string $conversationId, ?object $conversationUser = null): self
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Set the conversation message rows this turn wrote.
     */
    public function withStoredMessages(?string $userMessageId, ?string $assistantMessageId): self
    {
        $this->userMessageId = $userMessageId;
        $this->assistantMessageId = $assistantMessageId;

        return $this;
    }

    /**
     * Execute a callback with this response.
     */
    public function then(callable $callback): self
    {
        $callback($this);

        return $this;
    }

    /**
     * Get the raw provider replay state for the paused assistant turn, if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pausedProviderContentBlocks(): array
    {
        if (! $this->hasPendingApprovals()) {
            return [];
        }

        return $this->messages->whereInstanceOf(AssistantMessage::class)->last()?->providerContentBlocks ?? [];
    }
}
