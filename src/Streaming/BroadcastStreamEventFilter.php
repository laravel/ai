<?php

namespace Laravel\Ai\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent;

final class BroadcastStreamEventFilter
{
    /**
     * Determine if the given stream event should be broadcast for the agent.
     */
    public static function shouldBroadcast(object $agent, StreamEvent $event): bool
    {
        if (! method_exists($agent, 'exceptBroadcastStreamEvents')) {
            return true;
        }

        $except = $agent->exceptBroadcastStreamEvents();

        return $except === [] || ! in_array($event::class, $except, true);
    }
}
