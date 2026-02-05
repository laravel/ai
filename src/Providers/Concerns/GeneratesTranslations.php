<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Events\GeneratingTranslation;
use Laravel\Ai\Events\TranslationGenerated;
use Laravel\Ai\Prompts\TranslationPrompt;
use Laravel\Ai\Responses\TranslationResponse;

trait GeneratesTranslations
{
    /**
     * Translate audio to English.
     */
    public function translate(
        TranscribableAudio $audio,
        ?string $prompt = null,
        ?string $model = null,
    ): TranslationResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultTranslationModel();

        $translationPrompt = new TranslationPrompt($audio, $prompt, $this, $model);

        if (Ai::translationsAreFaked()) {
            Ai::recordTranslationGeneration($translationPrompt);
        }

        $this->events->dispatch(new GeneratingTranslation(
            $invocationId, $this, $model, $translationPrompt,
        ));

        return tap($this->translationGateway()->generateTranslation(
            $this, $model, $translationPrompt->audio, $translationPrompt->prompt
        ), function (TranslationResponse $response) use ($invocationId, $model, $translationPrompt) {
            $this->events->dispatch(new TranslationGenerated(
                $invocationId, $this, $model, $translationPrompt, $response
            ));
        });
    }
}
