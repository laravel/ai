<?php

namespace Laravel\Ai\Gateway\Prism;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Prompt;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;
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
use Laravel\Ai\Tools\Request as ToolRequest;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException as PrismVendorException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Image as PrismImage;

class PrismGateway implements Gateway
{
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
    ): TextResponse {
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema, $options),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools, $options);
        }

        try {
            $response = $request
                ->withMessages($this->toPrismMessages($messages))
                ->{$structured ? 'asStructured' : 'asText'}();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return $structured
            ? (new StructuredTextResponse(
                $response->structured,
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model),
            ))->withToolCallsAndResults(
                toolCalls: collect($response->toolCalls)->map(PrismTool::toLaravelToolCall(...)),
                toolResults: collect($response->toolResults)->map(PrismTool::toLaravelToolResult(...)),
            )->withSteps(PrismSteps::toLaravelSteps($response->steps))
            : (new TextResponse(
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model),
            ))->withMessages(
                PrismMessages::toLaravelMessages($response->messages)
            )->withSteps(PrismSteps::toLaravelSteps($response->steps));
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
    ): Generator {
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema, $options),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools, $options);
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
     * Add the given tools to the Prism request.
     */
    protected function addTools($request, array $tools, ?TextGenerationOptions $options = null)
    {
        return $request
            ->withTools(collect($tools)->map(function ($tool) {
                return (new PrismTool)
                    ->as(class_basename($tool))
                    ->for((string) $tool->description())
                    ->when(
                        ! empty($tool->schema(new JsonSchemaTypeFactory)),
                        fn ($prismTool) => $prismTool->withParameter(
                            new ObjectSchema($tool->schema(new JsonSchemaTypeFactory))
                        )
                    )
                    ->using(function ($arguments) use ($tool) {
                        $arguments = $arguments['schema_definition'] ?? [];

                        call_user_func($this->invokingToolCallback, $tool, $arguments);

                        return (string) tap($tool->handle(new ToolRequest($arguments)), function ($result) use ($tool, $arguments) {
                            call_user_func($this->toolInvokedCallback, $tool, $arguments, $result);
                        });
                    })
                    ->withoutErrorHandling();
            })->all())
            ->withToolChoice(ToolChoice::Auto)
            ->withMaxSteps($options?->maxSteps ?? round(count($tools) * 1.5));
    }

    /**
     * Create a Prism text request for the given provider, model, and prompt.
     */
    protected function createPrismTextRequest(Provider $provider, string $model, ?array $schema, ?TextGenerationOptions $options = null)
    {
        $request = tap(
            ! empty($schema) ? Prism::structured() : Prism::text(),
            fn ($prism) => $this->configure($prism, $provider, $model)
        );

        if (! empty($schema)) {
            $request = $request->withSchema(new ObjectSchema($schema));
        }

        if (! is_null($schema) &&
            ! empty($schema) &&
            $provider instanceof OpenAiProvider) {
            $request = $request->withProviderOptions([
                'schema' => [
                    'strict' => true,
                ],
            ]);
        }

        if ($provider instanceof AnthropicProvider) {
            $request = $request->withProviderOptions(array_filter([
                'use_tool_calling' => $schema ? true : null,
            ]))->withMaxTokens($options?->maxTokens ?? 64_000);
        } elseif (! is_null($options?->maxTokens)) {
            $request = $request->withMaxTokens($options->maxTokens);
        }

        if (! is_null($options?->temperature)) {
            $request = $request->usingTemperature($options->temperature);
        }

        return $request;
    }

    /**
     * Marshal the given messages into Prism's message format.
     */
    protected function toPrismMessages(array $messages): array
    {
        return PrismMessages::fromLaravelMessages(collect($messages))->all();
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
    ): ImageResponse {
        try {
            $response = Prism::image()
                ->using(static::toPrismProvider($provider), $model)
                ->withPrompt($prompt, $this->toPrismImageAttachments($attachments))
                ->withProviderOptions($provider->defaultImageOptions($size, $quality))
                ->withClientOptions([
                    'timeout' => 60,
                ])
                ->generate();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new ImageResponse(
            collect($response->images)->map(function ($image) {
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
        return collect($attachments)->map(function ($attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.get_class($attachment).']'
                );
            }

            $prismAttachment = match (true) {
                $attachment instanceof LocalImage => PrismImage::fromLocalPath($attachment->path, $attachment->mime),
                $attachment instanceof StoredImage => PrismImage::fromStoragePath($attachment->path, $attachment->disk),
                $attachment instanceof UploadedFile && static::isImage($attachment) => PrismImage::fromBase64(base64_encode($attachment->get()), $attachment->getClientMimeType()),
                default => throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).']'),
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
    ): AudioResponse {
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        try {
            $response = Prism::audio()
                ->using(static::toPrismProvider($provider), $model)
                ->withInput($text)
                ->withVoice($voice)
                ->withProviderOptions(array_filter([
                    'instructions' => $instructions,
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
    ): TranscriptionResponse {
        try {
            if ($provider->driver() === 'openai' && ! $diarize) {
                $model = str_replace('-diarize', '', $model);
            }

            $request = Prism::audio()
                ->using(static::toPrismProvider($provider), $model)
                ->withInput(match (true) {
                    $audio instanceof TranscribableAudio => Audio::fromBase64(
                        base64_encode($audio->content()), $audio->mimeType()
                    ),
                });

            if ($provider->driver() === 'openai') {
                $request->withProviderOptions(array_filter([
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : null,
                ]));
            }

            $response = $request->asText();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        return new TranscriptionResponse(
            $response->text,
            new Collection($response->additionalContent['segments'] ?? [])->map(function ($segment) {
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
        int $dimensions): EmbeddingsResponse
    {
        $request = tap(
            Prism::embeddings(),
            fn ($prism) => $this->configure($prism, $provider, $model)
        );

        (new Collection($inputs))->each($request->fromInput(...));

        $response = $request->asEmbeddings();

        return new EmbeddingsResponse(
            collect($response->embeddings)->map->embedding->all(),
            $response->usage->tokens,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Configure the given pending Prism request for the provider.
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using(
            static::toPrismProvider($provider),
            $model,
            ['api_key' => $provider->providerCredentials()['key']],
        );
    }

    /**
     * Map the given Laravel AI provider to a Prism provider.
     */
    protected static function toPrismProvider(Provider $provider): PrismProvider
    {
        return match ($provider->driver()) {
            'anthropic' => PrismProvider::Anthropic,
            'gemini' => PrismProvider::Gemini,
            'groq' => PrismProvider::Groq,
            'openai' => PrismProvider::OpenAI,
            'openrouter' => PrismProvider::OpenRouter,
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
