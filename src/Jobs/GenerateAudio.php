<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\PendingResponses\PendingAudioGeneration;
use Laravel\Ai\Providers\Provider;

class GenerateAudio implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PendingAudioGeneration $pendingAudio,
        public Lab|array|string|Provider|null $provider = null,
        public ?string $model = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn () => $this->pendingAudio->generate(
            $this->provider,
            $this->model,
        ));
    }
}
