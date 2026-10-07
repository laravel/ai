<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\TranscriptionFailed;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\TranscriptionResponse;
use Throwable;

trait GeneratesTranscriptions
{
    /**
     * Transcribe the given audio to text.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function transcribe(
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultTranscriptionModel();

        $prompt = new TranscriptionPrompt($audio, $language, $diarize, $this, $model, $timeout, $providerOptions);

        if (Ai::transcriptionsAreFaked()) {
            Ai::recordTranscriptionGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingTranscription(
            $invocationId, $this, $model, $prompt,
        ));

        try {
            $response = $this->transcriptionGateway()->generateTranscription(
                $this, $model, $prompt->audio, $prompt->language, $prompt->diarize, $prompt->timeout ?? 30, $prompt->providerOptions
            );
        } catch (Throwable $e) {
            $this->events->dispatch(new TranscriptionFailed(
                $invocationId, $this, $model, $prompt, $e,
            ));

            throw $e;
        }

        $this->events->dispatch(new TranscriptionGenerated(
            $invocationId, $this, $model, $prompt, $response,
        ));

        return $response;
    }
}
