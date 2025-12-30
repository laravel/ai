<?php

namespace Laravel\Ai\Gateway\Prism;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Prompt;
use Laravel\Ai\Messages\Attachments\Attachment;
use Laravel\Ai\Messages\Attachments\Image as ImageAttachment;
use Laravel\Ai\Messages\Attachments\LocalImage;
use Laravel\Ai\Messages\Attachments\StoredImage;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
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
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
    ): TextResponse {
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools);
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
                new Meta($provider->providerName(), $response->meta->model),
            ))->withToolCallsAndResults(
                toolCalls: collect($response->toolCalls)->map(PrismTool::toLaravelToolCall(...)),
                toolResults: collect($response->toolResults)->map(PrismTool::toLaravelToolResult(...)),
            )
            : (new TextResponse(
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->providerName(), $response->meta->model),
            ))->withMessages(PrismMessages::toLaravelMessages($response->messages));
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
    ): Generator {
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema),
            ! empty($schema),
        ];

        if (! empty($instructions)) {
            $request->withSystemPrompt($instructions);
        }

        if (count($tools) > 0) {
            $this->addTools($request, $tools);
        }

        try {
            $events = $request
                ->withMessages($this->toPrismMessages($messages))
                ->asStream();

            foreach ($events as $event) {
                if (! is_null($event = PrismStreamEvent::toLaravelStreamEvent(
                    $invocationId, $event, $provider->providerName(), $model
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
    protected function addTools($request, array $tools)
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
            ->withMaxSteps(round(count($tools) * 1.5));
    }

    /**
     * Create a Prism text request for the given provider, model, and prompt.
     */
    protected function createPrismTextRequest(Provider $provider, string $model, ?array $schema)
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

        if ($schema && $provider instanceof AnthropicProvider) {
            $request = $request->withProviderOptions([
                'use_tool_calling' => true,
            ]);
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
     * @param  array<ImageAttachment>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        Provider $provider,
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
            new Meta($provider->providerName(), $model),
        );
    }

    /**
     * Convert the given Laravel image attachments to Prism image attachments.
     */
    protected function toPrismImageAttachments(array $attachments): array
    {
        return collect($attachments)->map(function ($attachment) {
            if (! $attachment instanceof Attachment && ! $attachment instanceof UploadedFile) {
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

            if ($attachment instanceof Attachment && $attachment->name) {
                $prismAttachment->as($attachment->name);
            }

            return $prismAttachment;
        })->all();
    }

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        Provider $provider,
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
            new Meta($provider->providerName(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate text from the given audio.
     */
    public function generateTranscription(
        Provider $provider,
        string $model,
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse {
        try {
            if ($provider->providerName() === 'openai' && ! $diarize) {
                $model = str_replace('-diarize', '', $model);
            }

            $request = Prism::audio()
                ->using(static::toPrismProvider($provider), $model)
                ->withInput(match (true) {
                    $audio instanceof TranscribableAudio => Audio::fromBase64(
                        $audio->toBase64ForTranscription(), $audio->mimeTypeForTranscription()
                    ),
                    $audio instanceof UploadedFile => Audio::fromBase64(base64_encode($audio->get()), $audio->getClientMimeType()),
                });

            if ($provider->providerName() === 'openai') {
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
            new Meta($provider->providerName(), $model),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        Provider $provider,
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
            new Meta($provider->providerName(), $model),
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
        return match ($provider->providerName()) {
            'openai' => PrismProvider::OpenAI,
            'anthropic' => PrismProvider::Anthropic,
            'gemini' => PrismProvider::Gemini,
            'xai' => PrismProvider::XAI,
            'groq' => PrismProvider::Groq,
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
