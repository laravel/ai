<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Laravel\Ai\Gateway\Prism\PrismException;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Gateway\Prism\PrismUsage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Exceptions\PrismException as PrismVendorException;
use Prism\Prism\Facades\Prism;

class BedrockGateway extends PrismGateway
{
    use CreatesBedrockClient;

    /**
     * Configure the given pending Prism request for Bedrock.
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using(
            static::toPrismProvider($provider),
            $model,
            $this->bedrockProviderConfig($provider),
        );
    }

    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        if (! $provider instanceof Provider) {
            throw new InvalidArgumentException('Bedrock image provider must be an instance of ['.Provider::class.'].');
        }

        try {
            $response = Prism::image()
                ->using(
                    static::toPrismProvider($provider),
                    $model,
                    $this->bedrockProviderConfig($provider),
                )
                ->withPrompt($prompt, $this->toPrismImageAttachments($attachments))
                ->withProviderOptions($provider->defaultImageOptions($size, $quality))
                ->withClientOptions([
                    'timeout' => $timeout ?? 120,
                ])
                ->generate();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new ImageResponse(
            (new Collection($response->images))->map(function ($image) {
                return new GeneratedImage($image->base64, $image->mimeType);
            }),
            PrismUsage::toLaravelUsage($response->usage),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map the given Laravel AI provider to a Prism provider.
     */
    protected static function toPrismProvider(Provider $provider): PrismProvider
    {
        if ($provider->driver() !== 'bedrock') {
            return parent::toPrismProvider($provider);
        }

        if (! defined(PrismProvider::class.'::Bedrock')) {
            throw new InvalidArgumentException(
                'Prism provider [Bedrock] is not available. Install prism-php/bedrock and ensure Prism supports Bedrock.'
            );
        }

        /** @var PrismProvider */
        return constant(PrismProvider::class.'::Bedrock');
    }

}
