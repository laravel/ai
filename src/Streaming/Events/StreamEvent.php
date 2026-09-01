<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;

abstract class StreamEvent implements \Stringable
{
    public ?string $invocationId = null;

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
        try {
            Broadcast::on($channels)
                ->as($this->type())
                ->with($this->toArray())
                ->{$now ? 'sendNow' : 'send'}();
        } catch (BroadcastException $broadcastException) {
            report($broadcastException);
        }
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
     * Get the string representation of the event.
     */
    public function __toString(): string
    {
        return (string) json_encode($this->toArray());
    }
}
