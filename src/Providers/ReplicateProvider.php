<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\ReplicateGateway;

class ReplicateProvider extends Provider implements ImageProvider, TextProvider
{
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\StreamsText;

    protected ?TextGateway $textGateway = null;

    protected ?ImageGateway $imageGateway = null;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new ReplicateGateway;
    }

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self
    {
        $this->textGateway = $gateway;

        return $this;
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new ReplicateGateway;
    }

    /**
     * Set the provider's image gateway.
     */
    public function useImageGateway(ImageGateway $gateway): self
    {
        $this->imageGateway = $gateway;

        return $this;
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'meta/meta-llama-3-70b-instruct';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'meta/meta-llama-3-8b-instruct';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'meta/meta-llama-3.1-405b-instruct';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'black-forest-labs/flux-schnell';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                default => '1:1',
            },
            'num_outputs' => 1,
        ]);
    }
}
