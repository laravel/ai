<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\RerankingResponse;

class Reranked
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
        public RerankingResponse $response,
    ) {}
}
