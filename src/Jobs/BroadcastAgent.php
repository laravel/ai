<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Throwable;

use function Laravel\Ai\ulid;

class BroadcastAgent implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks;
    use Queueable;

    public int $tries = 1;

    public string $invocationId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public Decisions|string $prompt,
        public Channel|array $channels,
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        ?string $invocationId = null,
    ) {
        $this->invocationId = $invocationId ?: (string) Str::uuid7();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $streamedResponse = null;

        $without = WithoutBroadcasting::eventsFor($this->agent);

        $this->agent->stream($this->prompt, $this->attachments, $this->provider, $this->model)
            ->each(function (StreamEvent $event) use ($without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->withInvocationId($this->invocationId)->broadcastNow($this->channels);
            })
            ->then(function ($response) use (&$streamedResponse): void {
                $streamedResponse = $response;
            });

        $this->withCallbacks(fn () => $streamedResponse);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        (new Error(
            id: ulid(),
            type: 'stream_failed',
            message: 'The stream failed.',
            recoverable: false,
            timestamp: time(),
        ))->withInvocationId($this->invocationId)
            ->broadcastNow($this->channels);
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
