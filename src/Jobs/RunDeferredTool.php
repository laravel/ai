<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\DeferredToolManager;

class RunDeferredTool implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $toolClass,
        public array $arguments,
        public string $toolCallId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DeferredToolManager $manager): void
    {
        $manager->resume($this->toolClass, $this->arguments, $this->toolCallId);
    }
}
