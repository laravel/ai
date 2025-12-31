<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeImageGateway;
use Laravel\Ai\Prompts\ImagePrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeImages
{
    /**
     * The fake image gateway instance.
     */
    protected ?FakeImageGateway $fakeImageGateway = null;

    /**
     * All of the recorded image generations.
     */
    protected array $recordedImageGenerations = [];

    /**
     * Fake image generation.
     */
    public function fakeImages(Closure|array $responses = []): FakeImageGateway
    {
        return $this->fakeImageGateway = new FakeImageGateway($responses);
    }

    /**
     * Record an image generation.
     */
    public function recordImageGeneration(ImagePrompt $prompt): self
    {
        $this->recordedImageGenerations[] = $prompt;

        return $this;
    }

    /**
     * Assert that an image was generated matching a given truth test.
     */
    public function assertImageGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedImageGenerations))->filter(function (ImagePrompt $prompt) use ($callback) {
                return $callback($prompt);
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
            (new Collection($this->recordedImageGenerations))->filter(function (ImagePrompt $prompt) use ($callback) {
                return $callback($prompt);
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
     * Determine if image generation is faked.
     */
    public function imagesAreFaked(): bool
    {
        return $this->fakeImageGateway !== null;
    }

    /**
     * Get the fake image gateway.
     */
    public function fakeImageGateway(): ?FakeImageGateway
    {
        return $this->fakeImageGateway;
    }
}
