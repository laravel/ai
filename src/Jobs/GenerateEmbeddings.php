<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;

class GenerateEmbeddings implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PendingEmbeddingsGeneration $pendingEmbeddings,
        public Lab|array|string|null $provider = null,
        public ?string $model = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn () => $this->pendingEmbeddings->generate(
            $this->provider,
            $this->model,
        ));
    }
}
