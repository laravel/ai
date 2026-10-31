<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class RerankingFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
        public Throwable $exception,
    ) {}
}
