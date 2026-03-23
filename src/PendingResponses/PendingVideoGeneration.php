<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Jobs\GenerateVideo;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\QueuedVideoResponse;
use Laravel\Ai\Responses\VideoResponse;

class PendingVideoGeneration
{
    use Conditionable;

    public function __construct(
        public string $prompt,
        public ?string $seconds = null,
        public ?string $size = null,
        public ?int $timeout = null,
        public ?int $pollIntervalSeconds = null,
    ) {}

    /**
     * Clip duration in seconds (OpenAI: 4, 8, or 12).
     *
     * @param  '4'|'8'|'12'  $seconds
     */
    public function seconds(string $seconds): self
    {
        $this->seconds = $seconds;

        return $this;
    }

    /**
     * Output resolution (e.g. 1280x720).
     *
     * @param  '720x1280'|'1280x720'|'1024x1792'|'1792x1024'  $size
     */
    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Maximum time in seconds to wait for the remote job (including polling).
     */
    public function timeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Delay in seconds between status polls while waiting for completion.
     */
    public function pollInterval(?int $seconds): self
    {
        $this->pollIntervalSeconds = $seconds;

        return $this;
    }

    /**
     * Generate the video (blocking until the remote job completes or fails).
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): VideoResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_videos'), $model
        );

        $lastException = null;

        foreach ($providers as $providerName => $modelName) {
            $instance = Ai::fakeableVideoProvider($providerName);

            $modelName ??= $instance->defaultVideoModel();

            try {
                return $instance->video(
                    $this->prompt,
                    $modelName,
                    $this->seconds,
                    $this->size,
                    $this->timeout,
                    $this->pollIntervalSeconds,
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                event(new ProviderFailedOver($instance, $modelName, $e));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of a video.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedVideoResponse
    {
        return new QueuedVideoResponse(
            GenerateVideo::dispatch($this, $provider, $model),
        );
    }
}
