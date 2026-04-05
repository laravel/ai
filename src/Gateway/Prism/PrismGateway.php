<?php

namespace Laravel\Ai\Gateway\Prism;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Audio as AudioFile;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\Document as DocumentFile;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\LocalVideo;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Files\Video as VideoFile;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Exceptions\PrismException as PrismVendorException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio as PrismAudio;
use Prism\Prism\ValueObjects\Media\Document as PrismDocument;
use Prism\Prism\ValueObjects\Media\Image as PrismImage;
use Prism\Prism\ValueObjects\Media\Video as PrismVideo;

class PrismGateway implements Gateway
{
    use Concerns\AddsToolsToPrismRequests;
    use Concerns\CreatesPrismTextRequests;

    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    public function __construct(protected Dispatcher $events)
    {
        $this->invokingToolCallback = fn () => true;
        $this->toolInvokedCallback = fn () => true;
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
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema, $options, $timeout),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools, $options);
            $this->addProviderTools($provider, $request, $tools);
        }

        $prismMessages = $this->toPrismMessages($messages);

        try {
            $response = $request
                ->withMessages($prismMessages)
                ->{$structured ? 'asStructured' : 'asText'}();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        $citations = PrismCitations::toLaravelCitations(
            new Collection($response->additionalContent['citations'] ?? [])
        );

        return $structured
            ? (new StructuredTextResponse(
                $response->structured,
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model, $citations),
            ))->withToolCallsAndResults(
                toolCalls: (new Collection($response->toolCalls))->map(PrismTool::toLaravelToolCall(...)),
                toolResults: (new Collection($response->toolResults))->map(PrismTool::toLaravelToolResult(...)),
            )->withSteps(PrismSteps::toLaravelSteps($response->steps, $provider))
            : (new TextResponse(
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model, $citations),
            ))->withMessages(
                PrismMessages::toLaravelMessages($response->messages)->slice(count($prismMessages))->values()
            )->withSteps(PrismSteps::toLaravelSteps($response->steps, $provider));
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
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema, $options, $timeout),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools, $options);
            $this->addProviderTools($provider, $request, $tools);
        }

        try {
            $events = $request
                ->withMessages($this->toPrismMessages($messages))
                ->asStream();

            foreach ($events as $event) {
                if (! is_null($event = PrismStreamEvent::toLaravelStreamEvent(
                    $invocationId, $event, $provider->name(), $model
                ))) {
                    yield $event;
                }
            }
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }
    }

    /**
     * Marshal the given messages into Prism's message format.
     */
    protected function toPrismMessages(array $messages): array
    {
        return PrismMessages::fromLaravelMessages(new Collection($messages))->all();
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
        try {
            $response = Prism::image()
                ->using(static::toPrismProvider($provider), $model, array_filter([
                    ...$provider->additionalConfiguration(),
                    'api_key' => $provider->providerCredentials()['key'],
                ]))
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
     * Convert the given Laravel image attachments to Prism image attachments.
     */
    protected function toPrismImageAttachments(array $attachments): array
    {
        return (new Collection($attachments))->map(function ($attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.$attachment::class.']'
                );
            }

            $prismAttachment = match (true) {
                $attachment instanceof LocalImage => PrismImage::fromLocalPath($attachment->path, $attachment->mime),
                $attachment instanceof StoredImage => PrismImage::fromStoragePath($attachment->path, $attachment->disk),
                $attachment instanceof UploadedFile && static::isImage($attachment) => PrismImage::fromBase64(base64_encode($attachment->get()), $attachment->getClientMimeType()),
                default => throw new InvalidArgumentException('Unsupported attachment type ['.$attachment::class.']'),
            };

            if ($attachment instanceof File && $attachment->name) {
                $prismAttachment->as($attachment->name);
            }

            return $prismAttachment;
        })->all();
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

        try {
            $response = Prism::audio()
                ->using(static::toPrismProvider($provider), $model, array_filter([
                    ...$provider->additionalConfiguration(),
                    'api_key' => $provider->providerCredentials()['key'],
                ]))
                ->withClientOptions([
                    'timeout' => $timeout,
                ])
                ->withInput($text)
                ->withVoice($voice)
                ->withProviderOptions(array_filter([
                    'instructions' => $instructions,
                    'speed' => 1.0,
                ]))
                ->asAudio();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new AudioResponse(
            $response->audio->base64,
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate text from the given audio.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30
    ): TranscriptionResponse {
        try {
            if ($provider->driver() === 'openai' && ! $diarize) {
                $model = str_replace('-diarize', '', $model);
            }

            $request = Prism::audio()
                ->using(static::toPrismProvider($provider), $model, array_filter([
                    ...$provider->additionalConfiguration(),
                    'api_key' => $provider->providerCredentials()['key'],
                ]))
                ->withClientOptions([
                    'timeout' => $timeout,
                ])
                ->withInput(match (true) {
                    $audio instanceof TranscribableAudio => PrismAudio::fromBase64(
                        base64_encode($audio->content()), $audio->mimeType()
                    ),
                });

            if ($provider->driver() === 'openai') {
                $request->withProviderOptions(array_filter([
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : null,
                    'chunking_strategy' => $diarize ? 'auto' : null,
                ]));
            }

            $response = $request->asText();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new TranscriptionResponse(
            $response->text,
            (new Collection($response->additionalContent['segments'] ?? []))->map(function ($segment) {
                return new TranscriptionSegment(
                    $segment['text'],
                    $segment['speaker'],
                    $segment['start'],
                    $segment['end'],
                );
            }),
            PrismUsage::toLaravelUsage($response->usage),
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
        $request = tap(
            Prism::embeddings(),
            fn ($prism) => $this->configure($prism, $provider, $model)
        )->withClientOptions([
            'timeout' => $timeout,
        ]);

        $request->withProviderOptions(match ($provider->driver()) {
            'gemini' => ['outputDimensionality' => $dimensions],
            'ollama' => ['dimensions' => $dimensions],
            'openai' => ['dimensions' => $dimensions],
            'openrouter' => ['dimensions' => $dimensions],
            'voyageai' => ['outputDimension' => $dimensions],
            default => [],
        });

        (new Collection($inputs))->each(function ($input) use ($provider, $request) {
            match (true) {
                is_string($input) => $request->fromInput($input),
                $input instanceof ImageFile => $request->fromImage($this->toPrismEmbeddingImage($provider, $input)),
                $input instanceof AudioFile => $request->fromAudio($this->toPrismEmbeddingAudio($input)),
                $input instanceof DocumentFile => $request->fromDocument($this->toPrismEmbeddingDocument($provider, $input)),
                $input instanceof VideoFile => $request->fromVideo($this->toPrismEmbeddingVideo($input)),
                default => throw new InvalidArgumentException('Unsupported embeddings input type ['.$input::class.']'),
            };
        });

        try {
            $response = $request->asEmbeddings();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new EmbeddingsResponse(
            (new Collection($response->embeddings))->map->embedding->all(),
            $response->usage->tokens,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert the given Laravel embeddings image input to a Prism media value object.
     */
    protected function toPrismEmbeddingImage(EmbeddingProvider $provider, ImageFile $input): PrismImage
    {
        $prismInput = match (true) {
            $input instanceof ProviderImage => PrismImage::fromUrl(
                $this->resolveGeminiEmbeddingFileUri($provider, $input)
            ),
            $input instanceof Base64Image => PrismImage::fromBase64($input->base64, $input->mime),
            $input instanceof LocalImage => PrismImage::fromLocalPath($input->path, $input->mime),
            $input instanceof RemoteImage => PrismImage::fromUrl($input->url, $input->declaredMimeType()),
            $input instanceof StoredImage => PrismImage::fromStoragePath($input->path, $input->disk),
            default => throw new InvalidArgumentException('Unsupported image embeddings input type ['.$input::class.']'),
        };

        return $input->name() ? $prismInput->as($input->name()) : $prismInput;
    }

    /**
     * Convert the given Laravel embeddings audio input to a Prism media value object.
     */
    protected function toPrismEmbeddingAudio(AudioFile $input): PrismAudio
    {
        $prismInput = match (true) {
            $input instanceof Base64Audio => PrismAudio::fromBase64($input->base64, $input->mime),
            $input instanceof LocalAudio => PrismAudio::fromLocalPath($input->path, $input->mime),
            $input instanceof RemoteAudio => PrismAudio::fromUrl($input->url, $input->declaredMimeType()),
            $input instanceof StoredAudio => PrismAudio::fromStoragePath($input->path, $input->disk),
            default => throw new InvalidArgumentException('Unsupported audio embeddings input type ['.$input::class.']'),
        };

        return $input->name() ? $prismInput->as($input->name()) : $prismInput;
    }

    /**
     * Convert the given Laravel embeddings document input to a Prism media value object.
     */
    protected function toPrismEmbeddingDocument(EmbeddingProvider $provider, DocumentFile $input): PrismDocument
    {
        $prismInput = match (true) {
            $input instanceof ProviderDocument => PrismDocument::fromUrl(
                $this->resolveGeminiEmbeddingFileUri($provider, $input),
                $input->name(),
            ),
            $input instanceof Base64Document => PrismDocument::fromBase64($input->base64, $input->mime, $input->name()),
            $input instanceof LocalDocument => PrismDocument::fromLocalPath($input->path, $input->name()),
            $input instanceof RemoteDocument => PrismDocument::fromUrl($input->url, $input->name()),
            $input instanceof StoredDocument => PrismDocument::fromStoragePath($input->path, $input->disk, $input->name()),
            default => throw new InvalidArgumentException('Unsupported document embeddings input type ['.$input::class.']'),
        };

        return $input->name() ? $prismInput->as($input->name()) : $prismInput;
    }

    /**
     * Convert the given Laravel embeddings video input to a Prism media value object.
     */
    protected function toPrismEmbeddingVideo(VideoFile $input): PrismVideo
    {
        $prismInput = match (true) {
            $input instanceof Base64Video => PrismVideo::fromBase64($input->base64, $input->mime),
            $input instanceof LocalVideo => PrismVideo::fromLocalPath($input->path, $input->mime),
            $input instanceof RemoteVideo => PrismVideo::fromUrl($input->url, $input->declaredMimeType()),
            $input instanceof StoredVideo => PrismVideo::fromStoragePath($input->path, $input->disk),
            default => throw new InvalidArgumentException('Unsupported video embeddings input type ['.$input::class.']'),
        };

        return $input->name() ? $prismInput->as($input->name()) : $prismInput;
    }

    /**
     * Resolve a Gemini file ID into the provider file URI required by Prism embeddings.
     */
    protected function resolveGeminiEmbeddingFileUri(EmbeddingProvider $provider, ProviderImage|ProviderDocument $input): string
    {
        if (! $provider instanceof FileProvider) {
            throw new InvalidArgumentException(
                'Provider ['.$provider->driver().'] does not support retrieving files for embeddings.'
            );
        }

        $file = $provider->getFile($input->id());

        return $file->uri() ?? throw new InvalidArgumentException(
            'Provider file ['.$input->id().'] is missing a URI required for Gemini embeddings.'
        );
    }

    /**
     * Map the given Laravel AI provider to a Prism provider.
     */
    protected static function toPrismProvider(Provider $provider): PrismProvider
    {
        return match ($provider->driver()) {
            'azure' => PrismProvider::OpenAI,
            'deepseek' => PrismProvider::DeepSeek,
            'gemini' => PrismProvider::Gemini,
            'mistral' => PrismProvider::Mistral,
            'ollama' => PrismProvider::Ollama,
            'openai' => PrismProvider::OpenAI,
            'openrouter' => PrismProvider::OpenRouter,
            'voyageai' => PrismProvider::VoyageAI,
            'xai' => PrismProvider::XAI,
            default => throw new InvalidArgumentException('Gateway does not support provider ['.$provider.'].'),
        };
    }

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }
}
