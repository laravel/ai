<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class TranscriptionFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
        public Throwable $exception,
    ) {}
}
