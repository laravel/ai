<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\GeneratingVideo;
use Laravel\Ai\Events\VideoGenerated;
use Laravel\Ai\Prompts\VideoPrompt;
use Laravel\Ai\Responses\VideoResponse;

trait GeneratesVideos
{
    /**
     * Generate a video.
     */
    public function video(
        string $prompt,
        ?string $model = null,
        ?string $seconds = null,
        ?string $size = null,
        ?int $timeout = null,
        ?int $pollIntervalSeconds = null,
    ): VideoResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultVideoModel();

        $options = $this->defaultVideoOptions($seconds, $size);

        $videoPrompt = new VideoPrompt(
            $prompt,
            $options['seconds'],
            $options['size'],
            $this,
            $model,
        );

        if (Ai::videosAreFaked()) {
            Ai::recordVideoGeneration($videoPrompt);
        }

        $this->events->dispatch(new GeneratingVideo(
            $invocationId, $this, $model, $videoPrompt,
        ));

        return tap($this->videoGateway()->generateVideo(
            $this,
            $model,
            $videoPrompt->prompt,
            $videoPrompt->seconds,
            $videoPrompt->size,
            $timeout,
            $pollIntervalSeconds,
        ), function (VideoResponse $response) use ($invocationId, $videoPrompt, $model) {
            $this->events->dispatch(new VideoGenerated(
                $invocationId, $this, $model, $videoPrompt, $response,
            ));
        });
    }
}
