<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeVideoGateway;
use Laravel\Ai\Prompts\QueuedVideoPrompt;
use Laravel\Ai\Prompts\VideoPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeVideos
{
    /**
     * The fake video gateway instance.
     */
    protected ?FakeVideoGateway $fakeVideoGateway = null;

    /**
     * All of the recorded video generations.
     */
    protected array $recordedVideoGenerations = [];

    /**
     * All of the recorded video generations that were queued.
     */
    protected array $recordedQueuedVideoGenerations = [];

    /**
     * Fake video generation.
     */
    public function fakeVideos(Closure|array $responses = []): FakeVideoGateway
    {
        return $this->fakeVideoGateway = new FakeVideoGateway($responses);
    }

    /**
     * Record a video generation.
     */
    public function recordVideoGeneration(VideoPrompt|QueuedVideoPrompt $prompt): self
    {
        if ($prompt instanceof QueuedVideoPrompt) {
            $this->recordedQueuedVideoGenerations[] = $prompt;
        } else {
            $this->recordedVideoGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a video was generated matching a given truth test.
     */
    public function assertVideoGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedVideoGenerations))->contains(function (VideoPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An expected video generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a video was not generated matching a given truth test.
     */
    public function assertVideoNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedVideoGenerations))->doesntContain(function (VideoPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An unexpected video generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no videos were generated.
     */
    public function assertNoVideosGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedVideoGenerations,
            'Unexpected video generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued video generation was recorded matching a given truth test.
     */
    public function assertVideoQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedVideoGenerations))->contains(function (QueuedVideoPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An expected queued video generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued video generation was not recorded matching a given truth test.
     */
    public function assertVideoNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedVideoGenerations))->doesntContain(function (QueuedVideoPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An unexpected queued video generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued video generations were recorded.
     */
    public function assertNoVideosQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedVideoGenerations,
            'Unexpected queued video generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if video generation is faked.
     */
    public function videosAreFaked(): bool
    {
        return $this->fakeVideoGateway !== null;
    }

    /**
     * Get the fake video gateway.
     */
    public function fakeVideoGateway(): ?FakeVideoGateway
    {
        return $this->fakeVideoGateway;
    }
}
