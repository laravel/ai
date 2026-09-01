<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Responses\AudioResponse;

trait GeneratesAudio
{
    /**
     * Generate audio from the given text.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultAudioModel();

        $prompt = new AudioPrompt($text, $voice, $instructions, $this, $model, $timeout, $providerOptions);

        if (Ai::audioIsFaked()) {
            Ai::recordAudioGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingAudio(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->audioGateway()->generateAudio(
            $this, $model, $prompt->text, $prompt->voice, $prompt->instructions, $timeout, $prompt->providerOptions,
        ), function (AudioResponse $response) use ($invocationId, $model, $prompt): void {
            $this->events->dispatch(new AudioGenerated(
                $invocationId, $this, $model, $prompt, $response,
            ));
        });
    }
}
