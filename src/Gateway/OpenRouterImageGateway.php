<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class OpenRouterImageGateway implements ImageGateway
{
    use Concerns\HandlesRateLimiting;

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
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout($timeout ?? 120)
                ->post('https://openrouter.ai/api/v1/chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $this->buildMessages($prompt, $attachments),
                    'modalities' => ['image', 'text'],
                    ...$provider->defaultImageOptions($size, $quality),
                ]))
                ->throw()
        );

        $data = $response->json();

        $message = $data['choices'][0]['message'] ?? [];

        $images = collect($message['images'] ?? [])->map(function (array $image) {
            $url = $image['image_url']['url'] ?? '';

            if (preg_match('/^data:(image\/\w+);base64,(.+)$/', $url, $matches)) {
                return new GeneratedImage($matches[2], $matches[1]);
            }

            return null;
        })->filter()->values();

        $usage = $data['usage'] ?? [];

        return new ImageResponse(
            $images,
            new Usage($usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
            new Meta($provider->name(), $data['model'] ?? $model),
        );
    }

    /**
     * Build the messages array for the request.
     *
     * @param  array<ImageFile>  $attachments
     */
    protected function buildMessages(string $prompt, array $attachments): array
    {
        if (empty($attachments)) {
            return [
                ['role' => 'user', 'content' => $prompt],
            ];
        }

        $content = [];

        $content[] = ['type' => 'text', 'text' => $prompt];

        foreach ($attachments as $attachment) {
            $base64 = base64_encode($attachment->content());
            $mime = $attachment->mime ?? 'image/png';

            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mime};base64,{$base64}",
                ],
            ];
        }

        return [
            ['role' => 'user', 'content' => $content],
        ];
    }
}
