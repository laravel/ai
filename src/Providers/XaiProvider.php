<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\XaiImageGateway;

class XaiProvider extends Provider implements ImageProvider, TextProvider
{
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'grok-4-1-fast-reasoning';
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return new XaiImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'grok-2-image';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return [];
    }
}
