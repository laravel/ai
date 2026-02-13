<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Streaming\Events\StreamEvent;

class BroadcastAgent implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public string $prompt,
        public Channel|array $channels,
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn () => $this->agent->stream($this->prompt, $this->attachments, $this->provider, $this->model)
            ->each(function (StreamEvent $event) {
                $event->broadcastNow($this->channels);
            })
        );
    }

    /**
     * Get the display name for the queued job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
