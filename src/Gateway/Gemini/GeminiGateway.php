<?php

namespace Laravel\Ai\Gateway\Gemini;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Audio as AudioFile;
use Laravel\Ai\Files\Document as DocumentFile;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\Video as VideoFile;
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
        $model = $this->normalizeEmbeddingModel($model);

        $requests = array_map(fn (mixed $input) => [
            'model' => "models/{$model}",
            'content' => ['parts' => [$this->mapEmbeddingInput($provider, $input)]],
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
            isset($data['embedding']['values'])
                ? [$data['embedding']['values']]
                : (new Collection($data['embeddings'] ?? []))->pluck('values')->all(),
            $data['usageMetadata']['promptTokenCount'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Normalize model names accepted by the Gemini API.
     */
    protected function normalizeEmbeddingModel(string $model): string
    {
        return str_starts_with($model, 'models/') ? substr($model, 7) : $model;
    }

    /**
     * Map a Laravel embeddings input to a Gemini content part.
     */
    protected function mapEmbeddingInput(EmbeddingProvider $provider, mixed $input): array
    {
        if (is_string($input)) {
            return ['text' => $input];
        }

        if ($input instanceof ProviderImage || $input instanceof ProviderDocument) {
            return ['fileData' => $this->resolveEmbeddingProviderFileData($provider, $input)];
        }

        if ($input instanceof RemoteAudio || $input instanceof RemoteDocument || $input instanceof RemoteImage || $input instanceof RemoteVideo) {
            return [
                'fileData' => array_filter([
                    'mimeType' => $input->declaredMimeType(),
                    'fileUri' => $input->url,
                ]),
            ];
        }

        if ($input instanceof StorableFile && ($input instanceof AudioFile || $input instanceof DocumentFile || $input instanceof ImageFile || $input instanceof VideoFile)) {
            return [
                'inlineData' => [
                    'mimeType' => $input->mimeType() ?? $this->defaultEmbeddingMimeType($input),
                    'data' => base64_encode($input->content()),
                ],
            ];
        }

        throw new InvalidArgumentException('Unsupported embeddings input type ['.get_debug_type($input).']');
    }

    /**
     * Resolve a provider file ID to the URI Gemini expects in embedding parts.
     */
    protected function resolveEmbeddingProviderFileData(EmbeddingProvider $provider, ProviderImage|ProviderDocument $input): array
    {
        if (! $provider instanceof FileProvider) {
            throw new InvalidArgumentException(
                'Provider ['.$provider->driver().'] does not support retrieving files for embeddings.'
            );
        }

        $file = $provider->getFile($input->id());

        return array_filter([
            'mimeType' => $file->mimeType(),
            'fileUri' => $file->uri() ?? throw new InvalidArgumentException(
                'Provider file ['.$input->id().'] is missing a URI required for Gemini embeddings.'
            ),
        ]);
    }

    /**
     * Get a fallback MIME type for inline embeddings media.
     */
    protected function defaultEmbeddingMimeType(AudioFile|DocumentFile|ImageFile|VideoFile $input): string
    {
        return match (true) {
            $input instanceof AudioFile => 'audio/mpeg',
            $input instanceof ImageFile => 'image/png',
            $input instanceof VideoFile => 'video/mp4',
            default => 'application/octet-stream',
        };
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
