<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Providers\Provider;

class Reranking
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
    ) {}
}
