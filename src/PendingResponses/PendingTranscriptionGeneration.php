<?php

namespace Laravel\Ai\PendingResponses;

use Closure;
use Illuminate\Support\Traits\Conditionable;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Enums\Lab;
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
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;

class PendingTranscriptionGeneration
{
    use Conditionable;

    protected ?string $language = null;

    protected bool $diarize = false;

    protected int $timeout = 30;

    /** @var array<string, mixed>|SerializableClosure */
    protected array|SerializableClosure $providerOptions = [];

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
     * Specify the timeout (in seconds) for the transcription generation.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Specify provider-specific options for transcription generation.
     *
     * @param  array<string, mixed>|Closure(Provider): ?array<string, mixed>  $options
     */
    public function providerOptions(array|Closure $options): self
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Resolve provider options for the given provider.
     *
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(Provider $provider): array
    {
        if ($this->providerOptions instanceof SerializableClosure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        return $this->providerOptions;
    }

    /**
     * Generate the transcription.
     *
     * @throws FailoverableException if every configured provider fails to generate the transcription.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): TranscriptionResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_transcription'), $model
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableTranscriptionProvider($provider);

            $model ??= $provider->defaultTranscriptionModel();

            $providerOptions = $this->resolveProviderOptions($provider);

            try {
                return $provider->transcribe($this->audio, $this->language, $this->diarize, $model, $this->timeout, $providerOptions);
            } catch (FailoverableException $e) {
                $lastException = $e;

                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of the transcription.
     *
     * @throws LogicException if the audio attachment is not a local audio or an audio file stored on a filesystem disk.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedTranscriptionResponse
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
                    is_array($this->providerOptions) ? $this->providerOptions : [],
                )
            );

            return new QueuedTranscriptionResponse(new FakePendingDispatch);
        }

        return new QueuedTranscriptionResponse(
            GenerateTranscription::dispatch($this, $provider, $model),
        );
    }
}
