<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Responses\VideoResponse;

interface VideoGateway
{
    /**
     * Create a video job, poll until completion, and download the result.
     *
     * @param  '4'|'8'|'12'|null  $seconds
     * @param  '720x1280'|'1280x720'|'1024x1792'|'1792x1024'|null  $size
     */
    public function generateVideo(
        VideoProvider $provider,
        string $model,
        string $prompt,
        ?string $seconds = null,
        ?string $size = null,
        ?int $timeout = null,
        ?int $pollIntervalSeconds = null,
    ): VideoResponse;
}
