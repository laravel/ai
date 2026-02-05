<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Jobs\GenerateTranscription;
use Laravel\Ai\Prompts\QueuedTranscriptionPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\QueuedTranscriptionResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class PendingTranscriptionGeneration
{
    use Conditionable;

    protected ?string $language = null;

    protected bool $diarize = false;

    protected ?string $context = null;

    public function __construct(
        protected TranscribableAudio $audio,
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
     * Specify context to improve transcription accuracy.
     */
    public function context(string $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Generate the transcription.
     */
    public function generate(array|string|null $provider = null, ?string $model = null): TranscriptionResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_transcription'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableTranscriptionProvider($provider);

            $model ??= $provider->defaultTranscriptionModel();

            try {
                return $provider->transcribe($this->audio, $this->language, $this->diarize, $model, $this->context);
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

        if (Ai::transcriptionsAreFaked()) {
            Ai::recordTranscriptionGeneration(
                new QueuedTranscriptionPrompt(
                    $this->audio,
                    $this->language,
                    $this->diarize,
                    $provider,
                    $model,
                    $this->context,
                )
            );

            return new QueuedTranscriptionResponse(new FakePendingDispatch);
        }

        return new QueuedTranscriptionResponse(
            GenerateTranscription::dispatch($this, $provider, $model),
        );
    }
}
