<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

class InvokeAgent implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public string $prompt = '',
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public ?Decision $resume = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn () => $this->agent->prompt(
            $this->resume ?? $this->prompt, $this->attachments, $this->provider, $this->model
        ));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
