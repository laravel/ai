<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\PendingResponses\PendingTranslationGeneration;

class GenerateTranslation implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PendingTranslationGeneration $pendingTranslation,
        public array|string|null $provider = null,
        public ?string $model = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn () => $this->pendingTranslation->generate(
            $this->provider,
            $this->model,
        ));
    }
}
