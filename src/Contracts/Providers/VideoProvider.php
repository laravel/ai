<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Responses\VideoResponse;

interface VideoProvider
{
    /**
     * Generate a video (blocking until the remote job completes or fails).
     *
     * @param  '4'|'8'|'12'|null  $seconds
     * @param  '720x1280'|'1280x720'|'1024x1792'|'1792x1024'|null  $size
     */
    public function video(
        string $prompt,
        ?string $model = null,
        ?string $seconds = null,
        ?string $size = null,
        ?int $timeout = null,
        ?int $pollIntervalSeconds = null,
    ): VideoResponse;

    /**
     * Get the provider's video gateway.
     */
    public function videoGateway(): VideoGateway;

    /**
     * Set the provider's video gateway.
     */
    public function useVideoGateway(VideoGateway $gateway): self;

    /**
     * Get the name of the default video model.
     */
    public function defaultVideoModel(): string;

    /**
     * Get the default / normalized video options for the provider.
     *
     * @return array{seconds: string, size: string}
     */
    public function defaultVideoOptions(?string $seconds = null, ?string $size = null): array;
}
