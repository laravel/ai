<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\TranscriptionResponse;

trait GeneratesTranscriptions
{
    /**
     * Generate audio from the given text.
     */
    public function transcribe(
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
    ): TranscriptionResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultTranscriptionModel();

        $prompt = new TranscriptionPrompt($audio, $language, $diarize);

        $this->events->dispatch(new GeneratingTranscription(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->transcriptionGateway()->generateTranscription(
            $this, $model, $prompt->audio, $prompt->language, $prompt->diarize
        ), function (TranscriptionResponse $response) use ($invocationId, $model, $prompt) {
            $this->events->dispatch(new TranscriptionGenerated(
                $invocationId, $this, $model, $prompt, $response
            ));
        });
    }
}
