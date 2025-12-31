<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

trait InteractsWithFakeImages
{
    /**
     * Indicates if image generation is faked.
     */
    protected bool $imagesFaked = false;

    /**
     * The faked image responses.
     */
    protected Closure|array $fakeImageResponses = [];

    /**
     * All of the recorded image generations.
     */
    protected array $recordedImageGenerations = [];

    /**
     * The current index of the faked image responses.
     */
    protected int $fakeImageResponseIndex = 0;

    /**
     * Indicates if stray image generations should be prevented.
     */
    protected bool $preventStrayImageGenerations = false;

    /**
     * Fake image generation.
     */
    public function fakeImages(Closure|array $responses = []): self
    {
        $this->imagesFaked = true;
        $this->fakeImageResponses = $responses;

        return $this;
    }

    /**
     * Record an image generation.
     */
    public function recordImageGeneration(string $prompt, array $attachments, ?string $size, ?string $quality): self
    {
        $this->recordedImageGenerations[] = [
            'prompt' => $prompt,
            'attachments' => $attachments,
            'size' => $size,
            'quality' => $quality,
        ];

        return $this;
    }

    /**
     * Get the next fake image response.
     */
    public function nextFakeImageResponse(string $prompt, array $attachments, ?string $size, ?string $quality): ImageResponse
    {
        $response = is_array($this->fakeImageResponses)
            ? ($this->fakeImageResponses[$this->fakeImageResponseIndex] ?? null)
            : call_user_func($this->fakeImageResponses, $prompt, $attachments, $size, $quality);

        $this->fakeImageResponseIndex++;

        return $this->marshalImageResponse($response, $prompt, $attachments, $size, $quality);
    }

    /**
     * Marshal the given response into an ImageResponse instance.
     */
    protected function marshalImageResponse(mixed $response, string $prompt, array $attachments, ?string $size, ?string $quality): ImageResponse
    {
        if ($response instanceof Closure) {
            $response = $response($prompt, $attachments, $size, $quality);
        }

        if (is_null($response)) {
            if ($this->preventStrayImageGenerations) {
                throw new RuntimeException('Attempted image generation without a fake response.');
            }

            $response = base64_encode('fake-image-content');
        }

        if (is_string($response)) {
            return new ImageResponse(
                new Collection([new GeneratedImage($response)]),
                new Usage,
                new Meta,
            );
        }

        return $response;
    }

    /**
     * Assert that an image was generated matching a given truth test.
     */
    public function assertImageGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedImageGenerations))->filter(function (array $generation) use ($callback) {
                return $callback($generation['prompt'], $generation['attachments'], $generation['size'], $generation['quality']);
            })->count() > 0,
            'An expected image generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     */
    public function assertImageNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedImageGenerations))->filter(function (array $generation) use ($callback) {
                return $callback($generation['prompt'], $generation['attachments'], $generation['size'], $generation['quality']);
            })->count() === 0,
            'An unexpected image generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no images were generated.
     */
    public function assertNoImagesGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedImageGenerations,
            'Unexpected image generations were recorded.'
        );

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any image generation is not faked.
     */
    public function preventStrayImageGenerations(bool $prevent = true): self
    {
        $this->preventStrayImageGenerations = $prevent;

        return $this;
    }

    /**
     * Determine if image generation is faked.
     */
    public function areImagesFaked(): bool
    {
        return $this->imagesFaked;
    }
}
