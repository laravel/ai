<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;

class TextResponse implements \Stringable
{
    /** @var Collection<int, Message> */
    public Collection $messages;

    /** @var Collection<int, ToolCall> */
    public Collection $toolCalls;

    /** @var Collection<int, ToolResult> */
    public Collection $toolResults;

    /** @var Collection<int, Step> */
    public Collection $steps;

    /** @var Collection<int, PendingApproval> */
    public Collection $pendingApprovals;

    /**
     * Create a new text response instance.
     */
    public function __construct(public string $text, public Usage $usage, public Meta $meta)
    {
        $this->messages = new Collection;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
        $this->steps = new Collection;
        $this->pendingApprovals = new Collection;
    }

    /**
     * Provide the message context for the response.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function withMessages(Collection $messages): self
    {
        $this->messages = $messages;

        /** @var Collection<int, ToolCall> $toolCalls */
        $toolCalls = $this->messages
            ->whereInstanceOf(AssistantMessage::class)
            ->map(fn ($message) => $message->toolCalls)
            ->flatten();

        /** @var Collection<int, ToolResult> $toolResults */
        $toolResults = $this->messages
            ->whereInstanceOf(ToolResultMessage::class)
            ->map(fn ($message) => $message->toolResults)
            ->flatten();

        $this->withToolCallsAndResults($toolCalls, $toolResults);

        return $this;
    }

    /**
     * Provide the tool calls and results for the message.
     *
     * @param  Collection<int, ToolCall>  $toolCalls
     * @param  Collection<int, ToolResult>  $toolResults
     */
    public function withToolCallsAndResults(Collection $toolCalls, Collection $toolResults): self
    {
        // Filter Anthropic tool use for "JSON mode"...
        $this->toolCalls = $toolCalls->reject(
            fn ($toolCall): bool => $toolCall->name === 'output_structured_data'
        )->values();

        $this->toolResults = $toolResults->values();

        return $this;
    }

    /**
     * Provide the steps taken to generate the response.
     *
     * @param  Collection<int, Step>  $steps
     */
    public function withSteps(Collection $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    /**
     * Mark the response as waiting for tool approval.
     *
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public function withPendingApprovals(Collection $pendingApprovals): self
    {
        $this->pendingApprovals = $pendingApprovals->values();

        return $this;
    }

    /**
     * Determine whether the response is awaiting tool approval.
     */
    public function awaitingApproval(): bool
    {
        return $this->pendingApprovals->isNotEmpty();
    }

    /**
     * Get the string representation of the object.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
