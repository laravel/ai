<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use RuntimeException;

class FakeAudioGateway implements AudioGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
    ): AudioResponse {
        $audioPrompt = new AudioPrompt($text, $voice, $instructions, $provider, $model);

        return $this->nextResponse($provider, $model, $audioPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(AudioProvider $provider, string $model, AudioPrompt $prompt): AudioResponse
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        AudioProvider $provider,
        string $model,
        AudioPrompt $prompt
    ): AudioResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted audio generation without a fake response.');
            }

            $response = base64_encode('fake-audio-content');
        }

        if (is_string($response)) {
            return new AudioResponse($response, new Meta($provider->name(), $model));
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any audio generation is not faked.
     */
    public function preventStrayAudioGenerations(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
