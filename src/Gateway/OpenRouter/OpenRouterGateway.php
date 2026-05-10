<?php

namespace Laravel\Ai\Gateway\OpenRouter;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;

class OpenRouterGateway implements AudioGateway, EmbeddingGateway, ImageGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenRouterClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use InvokesTools;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $instructions,
            $messages,
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $instructions,
            $messages,
            0,
            null,
            [],
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
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
        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $imageConfig = array_filter([
            'aspect_ratio' => $imageOptions['aspect_ratio'] ?? null,
            'image_size' => $imageOptions['image_size'] ?? null,
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post('chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $this->buildImageMessages($prompt, $attachments),
                    'modalities' => ['image', 'text'],
                    'image_config' => $imageConfig ?: null,
                ]))
        );

        $data = $response->json();

        $message = $data['choices'][0]['message'] ?? [];

        $images = collect($message['images'] ?? [])->map(function (array $image) {
            $url = $image['image_url']['url'] ?? '';

            if (preg_match('/^data:(image\/[\w+.-]+);base64,(.+)$/', $url, $matches)) {
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
     * Build the messages array for an image generation request.
     *
     * @param  array<Image>  $attachments
     */
    protected function buildImageMessages(string $prompt, array $attachments): array
    {
        if (empty($attachments)) {
            return [['role' => 'user', 'content' => $prompt]];
        }

        return [['role' => 'user', 'content' => array_merge(
            [['type' => 'text', 'text' => $prompt]],
            $this->mapAttachments(collect($attachments)),
        )]];
    }

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
                'speed' => 1.0,
                'instructions' => $instructions,
            ])),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        $body = [
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions,
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->pluck('embedding')->all(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
