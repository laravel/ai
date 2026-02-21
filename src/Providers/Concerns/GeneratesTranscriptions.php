<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\TranscriptionResponse;

trait GeneratesTranscriptions
{
    /**
     * Transcribe the given audio to text.
     */
    public function transcribe(
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
        ?string $context = null,
        ?int $timeout = null,
    ): TranscriptionResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultTranscriptionModel();

        $prompt = new TranscriptionPrompt($audio, $language, $diarize, $this, $model, $context, $timeout);

        if (Ai::transcriptionsAreFaked()) {
            Ai::recordTranscriptionGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingTranscription(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->transcriptionGateway()->generateTranscription(
            $this, $model, $prompt->audio, $prompt->language, $prompt->diarize, $prompt->context, $prompt->timeout
        ), function (TranscriptionResponse $response) use ($invocationId, $model, $prompt) {
            $this->events->dispatch(new TranscriptionGenerated(
                $invocationId, $this, $model, $prompt, $response
            ));
        });
    }
}
