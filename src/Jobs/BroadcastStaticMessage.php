<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Contracts\Agent;

class BroadcastStaticMessage implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public string $message,
        public Channel|array $channels,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(function (): string {
            $this->agent->broadcastMessageNow($this->message, $this->channels);

            return $this->message;
        });
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
