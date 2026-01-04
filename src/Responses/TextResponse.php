<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class TextResponse
{
    public Collection $messages;

    public Collection $toolCalls;

    public Collection $toolResults;

    public Collection $steps;

    public function __construct(public string $text, public Usage $usage, public Meta $meta)
    {
        $this->messages = new Collection;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
        $this->steps = new Collection;
    }

    /**
     * Provide the message context for the response.
     */
    public function withMessages(Collection $messages): self
    {
        $this->messages = $messages;

        $this->withToolCallsAndResults(
            toolCalls: $this->messages
                ->whereInstanceOf(AssistantMessage::class)
                ->map(fn ($message) => $message->toolCalls)
                ->flatten(),
            toolResults: $this->messages
                ->whereInstanceOf(ToolResultMessage::class)
                ->map(fn ($message) => $message->toolResults)
                ->flatten(),
        );

        return $this;
    }

    /**
     * Provide the tool calls and results for the message.
     */
    public function withToolCallsAndResults(Collection $toolCalls, Collection $toolResults): self
    {
        // Filter Anthropic tool use for "JSON mode"...
        $this->toolCalls = $toolCalls->reject(
            fn ($toolCall) => $toolCall->name === 'output_structured_data'
        )->values();

        $this->toolResults = $toolResults;

        return $this;
    }

    /**
     * Provide the steps taken to generate the response.
     */
    public function withSteps(Collection $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    /**
     * Get the string representation of the object.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
