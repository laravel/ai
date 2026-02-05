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
use Laravel\Ai\Jobs\GenerateTranslation;
use Laravel\Ai\Prompts\QueuedTranslationPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\QueuedTranslationResponse;
use Laravel\Ai\Responses\TranslationResponse;
use LogicException;

class PendingTranslationGeneration
{
    use Conditionable;

    protected ?string $prompt = null;

    public function __construct(
        protected TranscribableAudio $audio,
    ) {}

    /**
     * Specify the prompt to guide the translation.
     */
    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * Generate the translation.
     */
    public function generate(array|string|null $provider = null, ?string $model = null): TranslationResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_translation'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableTranslationProvider($provider);

            $model ??= $provider->defaultTranslationModel();

            try {
                return $provider->translate($this->audio, $this->prompt, $model);
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Queue the generation of the translation.
     */
    public function queue(array|string|null $provider = null, ?string $model = null): QueuedTranslationResponse
    {
        if (! $this->audio instanceof StoredAudio &&
            ! $this->audio instanceof LocalAudio) {
            throw new LogicException('Only local audio or audio stored on a filesystem disk may be attachments for queued translation generations.');
        }

        if (Ai::translationsAreFaked()) {
            Ai::recordTranslationGeneration(
                new QueuedTranslationPrompt(
                    $this->audio,
                    $this->prompt,
                    $provider,
                    $model
                )
            );

            return new QueuedTranslationResponse(new FakePendingDispatch);
        }

        return new QueuedTranslationResponse(
            GenerateTranslation::dispatch($this, $provider, $model),
        );
    }
}
