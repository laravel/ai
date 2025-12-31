<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Jobs\GenerateTranscription;
use Laravel\Ai\Messages\Attachments\LocalAudio;
use Laravel\Ai\Messages\Attachments\StoredAudio;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\QueuedTranscriptionResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class PendingTranscriptionGeneration
{
    protected ?string $language = null;

    protected bool $diarize = false;

    public function __construct(
        protected TranscribableAudio|UploadedFile $audio,
    ) {}

    /**
     * Specify the language (ISO-639-1) of the audio being transcribed.
     */
    public function language(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Indicate that the transcript should be diarized.
     */
    public function diarize(bool $diarize = true): self
    {
        $this->diarize = $diarize;

        return $this;
    }

    /**
     * Generate the transcription.
     */
    public function generate(array|string|null $provider = null, ?string $model = null): TranscriptionResponse
    {
        if (Ai::areTranscriptionsFaked()) {
            Ai::recordTranscriptionGeneration($this->audio, $this->language, $this->diarize);

            return Ai::nextFakeTranscriptionResponse($this->audio, $this->language, $this->diarize);
        }

        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_transcription'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::transcriptionProvider($provider);

            $model ??= $provider->defaultTranscriptionModel();

            try {
                return $provider->transcribe($this->audio, $this->language, $this->diarize, $model);
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Queue the generation of the transcription.
     */
    public function queue(array|string|null $provider = null, ?string $model = null): QueuedTranscriptionResponse
    {
        if (! $this->audio instanceof StoredAudio &&
            ! $this->audio instanceof LocalAudio) {
            throw new LogicException('Only local audio or audio stored on a filesystem disk may be attachments for queued transcription generations.');
        }

        return new QueuedTranscriptionResponse(
            GenerateTranscription::dispatch($this, $provider, $model),
        );
    }
}
