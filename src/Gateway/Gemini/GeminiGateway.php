<?php

namespace Laravel\Ai\Gateway\Gemini;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class GeminiGateway implements Gateway
{
    use Concerns\BuildsTextRequests;
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
        [$body, $contents] = $this->buildTextRequestBody(
            $provider, $instructions, $messages, $tools, $schema, $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            $model,
            filled($schema),
            $tools,
            $schema,
            $options,
            $contents,
            $instructions,
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
        [$body, $contents] = $this->buildTextRequestBody(
            $provider, $instructions, $messages, $tools, $schema, $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post("models/{$model}:streamGenerateContent?alt=sse", $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $contents,
            $instructions,
            0,
            null,
            $timeout,
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
        $parts = [['text' => $prompt]];

        if (filled($attachments)) {
            $parts = array_merge($parts, $this->mapAttachments(collect($attachments)));
        }

        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => array_filter([
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => array_filter([
                    'imageSize' => $imageOptions['image_size'] ?? null,
                    'aspectRatio' => $imageOptions['aspect_ratio'] ?? null,
                ]),
            ]),
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)->post("models/{$model}:generateContent", $body),
        );

        $data = $response->json();

        $images = (new Collection($data['candidates'][0]['content']['parts'] ?? []))
            ->filter(fn ($part) => isset($part['inlineData']))
            ->map(fn ($part) => new GeneratedImage(
                $part['inlineData']['data'],
                $part['inlineData']['mimeType'],
            ));

        return new ImageResponse(
            $images,
            new Usage(0, 0),
            new Meta($provider->name(), $model),
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
        $requests = array_map(fn (string $input) => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $input]]],
            'output_dimensionality' => $dimensions,
        ], $inputs);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:batchEmbedContents", [
                'requests' => $requests,
            ]),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            (new Collection($data['embeddings'] ?? []))->pluck('values')->all(),
            $data['usageMetadata']['promptTokenCount'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate audio from the given text.
     *
     * @throws LogicException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        throw new LogicException('The Gemini provider does not support audio generation.');
    }

    /**
     * Generate text from the given audio.
     *
     * @throws LogicException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $context = null,
        int $timeout = 30,
    ): TranscriptionResponse {
        throw new LogicException('The Gemini provider does not support transcription.');
    }

    /**
     * Get an HTTP client for the Gemini API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::baseUrl(rtrim($config['url'] ?? 'https://generativelanguage.googleapis.com/v1beta/', '/'))
            ->withHeaders(['x-goog-api-key' => $provider->providerCredentials()['key']])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
