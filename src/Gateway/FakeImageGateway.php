<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;

class FakeImageGateway implements ImageGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Generate an image.
     *
     * @param  array<ImageAttachment>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
    ): ImageResponse {
        $imagePrompt = new ImagePrompt($prompt, $attachments, $size, $quality, $provider, $model);

        return $this->nextResponse($provider, $model, $imagePrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(ImageProvider $provider, string $model, ImagePrompt $prompt): ImageResponse
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
        ImageProvider $provider,
        string $model,
        ImagePrompt $prompt
    ): ImageResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted image generation without a fake response.');
            }

            $response = base64_encode('fake-image-content');
        }

        if (is_string($response)) {
            return new ImageResponse(
                new Collection([new GeneratedImage($response)]),
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any image generation is not faked.
     */
    public function preventStrayImageGenerations(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
