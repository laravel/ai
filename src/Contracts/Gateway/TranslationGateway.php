<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Providers\TranslationProvider;
use Laravel\Ai\Responses\TranslationResponse;

interface TranslationGateway
{
    /**
     * Translate audio to English.
     */
    public function generateTranslation(
        TranslationProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $prompt = null,
    ): TranslationResponse;
}
