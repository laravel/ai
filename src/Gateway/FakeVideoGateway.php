<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Prompts\VideoPrompt;
use Laravel\Ai\Responses\Data\GeneratedVideo;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\VideoResponse;
use RuntimeException;

class FakeVideoGateway implements VideoGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function generateVideo(
        VideoProvider $provider,
        string $model,
        string $prompt,
        ?string $seconds = null,
        ?string $size = null,
        ?int $timeout = null,
        ?int $pollIntervalSeconds = null,
    ): VideoResponse {
        $seconds = $seconds ?? '4';
        $size = $size ?? '1280x720';

        $videoPrompt = new VideoPrompt($prompt, $seconds, $size, $provider, $model);

        return $this->nextResponse($provider, $model, $videoPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(VideoProvider $provider, string $model, VideoPrompt $prompt): VideoResponse
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
        VideoProvider $provider,
        string $model,
        VideoPrompt $prompt,
    ): VideoResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted video generation without a fake response.');
            }

            $response = 'fake-video-content';
        }

        if (is_string($response)) {
            return new VideoResponse(
                new Collection([new GeneratedVideo($response)]),
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any video generation is not faked.
     */
    public function preventStrayVideos(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
