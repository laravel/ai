<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Xai\XaiGateway;
use Laravel\Ai\Gateway\Xai\XaiImageGateway;

class XaiProvider extends Provider implements ImageProvider, TextProvider
{
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasImageGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new XaiGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'grok-4-1-fast-reasoning';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'grok-4-1-fast-reasoning';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'grok-4-1-fast-reasoning';
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new XaiImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'grok-imagine-image';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                null => null,
                default => $size,
            },
            'resolution' => match ($quality) {
                'low', '1K' => '1k',
                'medium', '2K' => '2k',
                'high', '4K' => '2k',
                default => null,
            },
        ]);
    }
}
