<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\InvocationContext;

class InvokeAgent implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public string $prompt,
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public ?string $parentInvocationId = null,
        public ?string $rootInvocationId = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $invoke = fn () => $this->withCallbacks(fn () => $this->agent->prompt(
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model,
        ));

        // No dispatching context to nest beneath - parent and root travel together, set by queue()...
        $parentId = $this->parentInvocationId;

        if ($parentId === null) {
            $invoke();

            return;
        }

        // Re-establish the dispatching invocation so the queued agent nests beneath it...
        InvocationContext::run(
            InvocationContext::rehydrate($parentId, $this->rootInvocationId),
            $invoke,
        );
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
