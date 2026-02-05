<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Files\TranscribableAudio;

class QueuedTranslationPrompt
{
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $prompt,
        public readonly array|string|null $provider,
        public readonly ?string $model,
    ) {}
}
