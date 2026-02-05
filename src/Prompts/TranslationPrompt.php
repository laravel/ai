<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Providers\TranslationProvider;

class TranslationPrompt
{
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $prompt,
        public readonly TranslationProvider $provider,
        public readonly string $model,
    ) {}
}
