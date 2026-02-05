<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\TranslationPrompt;
use Laravel\Ai\Providers\Provider;

class GeneratingTranslation
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranslationPrompt $prompt,
    ) {}
}
