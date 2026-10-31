<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class ClassificationFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ClassificationPrompt $prompt,
        public Throwable $exception,
    ) {}
}
