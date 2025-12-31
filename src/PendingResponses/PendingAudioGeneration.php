<?php

namespace Laravel\Ai\PendingResponses;

use Laravel\Ai\Ai;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Jobs\GenerateAudio;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\QueuedAudioResponse;

class PendingAudioGeneration
{
    protected string $voice = 'default-female';

    protected ?string $instructions = null;

    public function __construct(
        protected string $text,
    ) {}

    /**
     * Specify a specific voice for the generated audio.
     */
    public function voice(string $voice): self
    {
        $this->voice = $voice;

        return $this;
    }

    /**
     * Indicate that the voice should be male.
     */
    public function male(): self
    {
        $this->voice = 'default-male';

        return $this;
    }

    /**
     * Indicate that the voice should be female.
     */
    public function female(): self
    {
        $this->voice = 'default-female';

        return $this;
    }

    /**
     * Provide free-form instructions guiding how the audio should sound.
     */
    public function instructions(string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Generate the audio.
     */
    public function generate(array|string|null $provider = null, ?string $model = null): AudioResponse
    {
        if (Ai::isAudioFaked()) {
            $prompt = new AudioPrompt($this->text, $this->voice, $this->instructions);

            Ai::recordAudioGeneration($prompt);

            return Ai::nextFakeAudioResponse($prompt);
        }

        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_audio'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::audioProvider($provider);

            $model ??= $provider->defaultAudioModel();

            try {
                return $provider->audio(
                    $this->text, $this->voice, $this->instructions, $model
                );
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Queue the generation of the audio.
     */
    public function queue(array|string|null $provider = null, ?string $model = null): QueuedAudioResponse
    {
        return new QueuedAudioResponse(
            GenerateAudio::dispatch($this, $provider, $model),
        );
    }
}
