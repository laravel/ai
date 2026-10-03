<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

class EmbeddingsFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public EmbeddingsPrompt $prompt,
        public Throwable $exception,
    ) {}
}
