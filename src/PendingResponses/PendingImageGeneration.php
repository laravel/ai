<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Jobs\GenerateImage;
use Laravel\Ai\Prompts\QueuedImagePrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\QueuedImageResponse;
use LogicException;

class PendingImageGeneration
{
    use Conditionable;

    public array $attachments = [];

    public ?string $size = null;

    public ?string $quality = null;

    public ?int $timeout = null;

    public function __construct(
        public string $prompt,
    ) {}

    /**
     * Provide the reference images that should be sent with the request.
     *
     * @param  array<Image>  $attachments
     */
    public function attachments(array $attachments): self
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * Specify the size / aspect ratio of the generated image.
     *
     * @param  '3:2'|'2:3'|'1:1'  $size
     */
    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Indicate that the generated image should have a square aspect ratio.
     */
    public function square(): self
    {
        $this->size = '1:1';

        return $this;
    }

    /**
     * Indicate that the generated image should have a portrait aspect ratio.
     */
    public function portrait(): self
    {
        $this->size = '2:3';

        return $this;
    }

    /**
     * Indicate that the generated image should have a landscape aspect ratio.
     */
    public function landscape(): self
    {
        $this->size = '3:2';

        return $this;
    }

    /**
     * Specify the quality of the generated image.
     *
     * @param  'low'|'medium'|'high'  $quality
     */
    public function quality(string $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Specify the timeout for the image generation request.
     */
    public function timeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Generate the image.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): ImageResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_images'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableImageProvider($provider);

            $model ??= $provider->defaultImageModel();

            try {
                return $provider->image(
                    $this->prompt, $this->attachments, $this->size, $this->quality, $model, $this->timeout
                );
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Queue the generation of an image.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedImageResponse
    {
        $this->ensureAttachmentsAreQueueable();

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration(
                new QueuedImagePrompt(
                    $this->prompt,
                    $this->attachments,
                    $this->size,
                    $this->quality,
                    $provider,
                    $model
                )
            );

            return new QueuedImageResponse(new FakePendingDispatch);
        }

        return new QueuedImageResponse(
            GenerateImage::dispatch($this, $provider, $model),
        );
    }

    /**
     * Ensure all of the attachments are queueable.
     */
    protected function ensureAttachmentsAreQueueable(): void
    {
        foreach ($this->attachments as $attachment) {
            if (! $attachment instanceof StoredImage &&
                ! $attachment instanceof LocalImage) {
                throw new LogicException('Only local images or images stored on a filesystem disk may be attachments for queued image generations.');
            }
        }
    }
}
