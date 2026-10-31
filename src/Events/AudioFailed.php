<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class AudioFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
        public Throwable $exception,
    ) {}
}
