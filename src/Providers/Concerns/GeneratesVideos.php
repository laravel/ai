<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Responses\VideoResponse;

trait GeneratesVideos
{
    /**
     * Generate a video.
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
    ): VideoResponse {
        $model ??= $this->defaultVideoModel();

        $options = $this->defaultVideoOptions($seconds, $size);

        return $this->videoGateway()->generateVideo(
            $this,
            $model,
            $prompt,
            $options['seconds'],
            $options['size'],
            $timeout,
            $pollIntervalSeconds,
        );
    }
}
