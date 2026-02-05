<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranslationGateway;
use Laravel\Ai\Contracts\Providers\TranslationProvider;
use Laravel\Ai\Prompts\TranslationPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranslationResponse;
use RuntimeException;

class FakeTranslationGateway implements TranslationGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Translate audio to English.
     */
    public function generateTranslation(
        TranslationProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $prompt = null,
    ): TranslationResponse {
        $translationPrompt = new TranslationPrompt($audio, $prompt, $provider, $model);

        return $this->nextResponse($provider, $model, $translationPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(TranslationProvider $provider, string $model, TranslationPrompt $prompt): TranslationResponse
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
        TranslationProvider $provider,
        string $model,
        TranslationPrompt $prompt
    ): TranslationResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted translation generation without a fake response.');
            }

            $response = 'Fake translation text.';
        }

        if (is_string($response)) {
            return new TranslationResponse(
                $response,
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any translation generation is not faked.
     */
    public function preventStrayTranslations(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
