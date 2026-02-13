<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Broadcast;

abstract class StreamEvent
{
    public ?string $invocationId = null;

    /**
     * Broadcast the stream event using the queue.
     */
    public function broadcast(Channel|array $channels, bool $now = false): void
    {
        foreach (Arr::wrap($channels) as $channel) {
            $event = Broadcast::on((string) $channel);

            $event->as($this->type())
                ->with($this->toArray())
                ->{$now ? 'sendNow' : 'send'}();
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
