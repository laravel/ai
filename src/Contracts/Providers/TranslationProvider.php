<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranslationGateway;
use Laravel\Ai\Responses\TranslationResponse;

interface TranslationProvider
{
    /**
     * Translate audio to English.
     */
    public function translate(
        TranscribableAudio $audio,
        ?string $prompt = null,
        ?string $model = null,
    ): TranslationResponse;

    /**
     * Get the provider's translation gateway.
     */
    public function translationGateway(): TranslationGateway;

    /**
     * Set the provider's translation gateway.
     */
    public function useTranslationGateway(TranslationGateway $gateway): self;

    /**
     * Get the name of the default translation model.
     */
    public function defaultTranslationModel(): string;
}
