<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;

abstract class StreamEvent
{
    public ?string $invocationId = null;

    public ?string $parentInvocationId = null;

    public ?string $parentToolCallId = null;

    /**
     * @var array<int, string>
     */
    public array $ancestorToolCallIds = [];

    /**
     * Get the array representation of the event.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Broadcast the stream event using the queue.
     */
    public function broadcast(Channel|array $channels, bool $now = false): void
    {
        Broadcast::on($channels)
            ->as($this->type())
            ->with($this->toArray())
            ->{$now ? 'sendNow' : 'send'}();
    }

    /**
     * Broadcast the stream event immediately.
     */
    public function broadcastNow(Channel|array $channels): void
    {
        $this->broadcast($channels, now: true);
    }

    /**
     * Get the event's type.
     */
    public function type(): string
    {
        return $this->toArray()['type'];
    }

    /**
     * Set the invocation ID associated with the event.
     */
    public function withInvocationId(string $id): self
    {
        $this->invocationId = $id;

        return $this;
    }

    /**
     * Set the parent invocation and tool call that produced this nested event.
     *
     * @param  array<int, string>  $ancestorToolCallIds
     */
    public function withParent(string $invocationId, string $toolCallId, array $ancestorToolCallIds = []): self
    {
        $wasNested = $this->isNested();

        if (! $wasNested) {
            $this->parentInvocationId = $invocationId;
            $this->parentToolCallId = $toolCallId;
        }

        $prefix = $ancestorToolCallIds === [] ? [$toolCallId] : $ancestorToolCallIds;
        $current = $wasNested ? $this->ancestorToolCallIds : [$toolCallId];

        $this->ancestorToolCallIds = array_values(array_unique([...$prefix, ...$current]));

        return $this;
    }

    /**
     * Determine whether the event came from a nested tool or sub-agent stream.
     */
    public function isNested(): bool
    {
        return $this->parentInvocationId !== null;
    }

    /**
     * Get the nested tool-call depth for this event.
     */
    public function depth(): int
    {
        return count($this->ancestorToolCallIds);
    }

    /**
     * Get the nested provenance fields for serialization.
     *
     * @return array<string, mixed>
     */
    protected function nestedPayload(): array
    {
        if (! $this->isNested()) {
            return [];
        }

        return [
            'parent_invocation_id' => $this->parentInvocationId,
            'parent_tool_call_id' => $this->parentToolCallId,
            'ancestor_tool_call_ids' => $this->ancestorToolCallIds,
            'depth' => $this->depth(),
        ];
    }

    /**
     * Get a stable Vercel data-part ID for this nested event.
     */
    public function nestedVercelPartId(): string
    {
        return 'subagent:'.implode('/', $this->ancestorToolCallIds);
    }

    /**
     * Get the array representation of the event that is compatible with the Vercel AI SDK.
     */
    public function toVercelProtocolArray(): ?array
    {
        return null;
    }

    /**
     * Get the string representation of the event.
     */
    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
