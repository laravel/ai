<?php

namespace Laravel\Ai\Gateway\OpenRouter;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioMimeTypes;
use Laravel\Ai\Gateway\Concerns\WrapsPcmAudio;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;
use RuntimeException;

class OpenRouterGateway implements Gateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenRouterClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;
    use ResolvesAudioMimeTypes;
    use WrapsPcmAudio;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Map a provider tool to an OpenRouter tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('OpenRouter does not support ['.class_basename($tool).'] provider tools.'),
        };
    }

    /**
     * Map a web fetch tool to an OpenRouter server tool definition.
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web fetch.');
        }

        return [
            'type' => 'openrouter:web_fetch',
            ...$provider->webFetchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an OpenRouter server tool definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');
        }

        return [
            'type' => 'openrouter:web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
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
                    'modalities' => ['image'],
                    'image_config' => $imageConfig ?: null,
                ]))
        );

        $data = $response->json();

        $message = $data['choices'][0]['message'] ?? [];

        $images = collect($message['images'] ?? [])->map(function (array $image): ?GeneratedImage {
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
        if ($attachments === []) {
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
        ?float $speed = null,
        ?string $format = null,
        int $timeout = 30,
    ): AudioResponse {
        $format ??= $this->audioResponseFormat($model);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $this->resolveVoice($model, $voice),
                'response_format' => $format,
                'speed' => $speed ?? 1.0,
                'instructions' => $instructions,
            ])),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            $this->audioResponseMimeType($format),
        );
    }

    /**
     * Resolve the alias voice for the given model.
     */
    protected function resolveVoice(string $model, string $voice): string
    {
        if ($this->isGeminiTtsModel($model)) {
            return match ($voice) {
                'default-male' => 'Puck',
                'default-female' => 'Kore',
                default => $voice,
            };
        }

        return match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };
    }

    /**
     * Resolve the response_format the model accepts. Gemini TTS only accepts pcm.
     */
    protected function audioResponseFormat(string $model): string
    {
        return $this->isGeminiTtsModel($model) ? 'pcm' : 'mp3';
    }

    protected function isGeminiTtsModel(string $model): bool
    {
        return str_contains($model, 'gemini') && str_contains($model, 'tts');
    }

    /**
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
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
        array $providerOptions = [],
    ): TranscriptionResponse {
        if ($diarize) {
            throw new LogicException(
                'OpenRouter does not support diarized transcription. Use the OpenAI, ElevenLabs, Mistral, or Gemini provider for diarization.'
            );
        }

        $mimeType = $audio->mimeType();
        $content = $audio->content();

        if ($mimeType === 'audio/pcm') {
            $content = $this->pcmToWav($content);
            $mimeType = 'audio/wav';
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                'model' => $model,
                'input_audio' => [
                    'data' => base64_encode($content),
                    'format' => $this->audioFormat($mimeType),
                ],
                'language' => $language,
            ]))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect(),
            new Usage(
                Arr::get($data, 'usage.input_tokens', 0),
                Arr::get($data, 'usage.output_tokens', 0),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map an audio MIME type to OpenRouter's input_audio.format value.
     */
    protected function audioFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            default => throw new InvalidArgumentException(
                "Unsupported audio MIME type [{$mimeType}] for OpenRouter transcription. Supported types: audio/wav, audio/mp3, audio/mpeg, audio/flac, audio/m4a, audio/mp4, audio/ogg, audio/webm, audio/aac."
            ),
        };
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
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $body = array_merge($providerOptions, [
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions,
        ]);

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
