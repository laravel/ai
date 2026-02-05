<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use RuntimeException;

class FakeTranscriptionGateway implements TranscriptionGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Generate text from the given audio.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $context = null,
    ): TranscriptionResponse {
        $transcriptionPrompt = new TranscriptionPrompt($audio, $language, $diarize, $provider, $model, $context);

        return $this->nextResponse($provider, $model, $transcriptionPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(TranscriptionProvider $provider, string $model, TranscriptionPrompt $prompt): TranscriptionResponse
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
        TranscriptionProvider $provider,
        string $model,
        TranscriptionPrompt $prompt
    ): TranscriptionResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted transcription generation without a fake response.');
            }

            $response = 'Fake transcription text.';
        }

        if (is_string($response)) {
            return new TranscriptionResponse(
                $response,
                new Collection([
                    new TranscriptionSegment($response, 'Speaker 1', 0.0, 1.0),
                ]),
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any transcription generation is not faked.
     */
    public function preventStrayTranscriptions(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
