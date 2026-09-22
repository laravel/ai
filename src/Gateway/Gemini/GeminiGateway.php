<?php

namespace Laravel\Ai\Gateway\Gemini;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Concerns\WrapsPcmAudio;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use RuntimeException;

class GeminiGateway implements Gateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesGeminiClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsEmbeddingInputs;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;
    use WrapsPcmAudio;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, filled($schema))->withRawResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('interactions?alt=sse', array_merge($body, ['stream' => true])),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $content = [['type' => 'text', 'text' => $prompt]];

        if (filled($attachments)) {
            $content = array_merge($content, $this->mapAttachments(collect($attachments)));
        }

        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $body = array_merge(['store' => false], $providerOptions, [
            'model' => $model,
            'input' => $content,
            'response_format' => array_replace_recursive($providerOptions['response_format'] ?? [], array_filter([
                'type' => 'image',
                'image_size' => $imageOptions['image_size'] ?? null,
                'aspect_ratio' => $imageOptions['aspect_ratio'] ?? null,
            ])),
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)->post('interactions', $body),
        );

        $data = $response->json();

        $images = (new Collection($this->outputBlocks($data, 'image')))
            ->map(fn ($block): GeneratedImage => new GeneratedImage(
                $block['data'],
                $block['mime_type'] ?? 'image/png',
            ));

        return new ImageResponse(
            $images,
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get the content blocks of the given type from an interaction response.
     */
    protected function outputBlocks(array $data, string $type): array
    {
        return (new Collection($data['steps'] ?? []))
            ->flatMap(fn (array $step): array => $step['content'] ?? [])
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? '') === $type && isset($block['data']))
            ->values()
            ->all();
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
        $model = $this->normalizeEmbeddingModel($model);

        $requests = array_map(fn (mixed $input): array => array_merge(Arr::except($providerOptions, 'output_dimensionality'), [
            'model' => "models/{$model}",
            'content' => ['parts' => [$this->mapEmbeddingInput($input)]],
            'outputDimensionality' => $dimensions,
        ]), $inputs);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:batchEmbedContents", [
                'requests' => $requests,
            ]),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            (new Collection($data['embeddings'] ?? []))->pluck('values')->all(),
            new Usage($data['usageMetadata']['promptTokenCount'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate audio from the given text.
     *
     * @param  array<string, mixed>  $providerOptions
     *
     * @throws RuntimeException if Gemini returns no audio data or invalid base64 audio.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $body = array_merge(['store' => false], $providerOptions, [
            'model' => $model,
            'input' => $instructions !== null && trim($instructions) !== ''
                ? trim($instructions)."\n\n".$text
                : $text,
            'response_format' => ['type' => 'audio'],
            'generation_config' => array_replace_recursive($providerOptions['generation_config'] ?? [], [
                'speech_config' => [[
                    'voice' => match ($voice) {
                        'default-female' => 'Kore',
                        'default-male' => 'Puck',
                        default => $voice,
                    },
                ]],
            ]),
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->json();

        $audio = $this->outputBlocks($data, 'audio')[0] ?? [];

        $encodedAudio = $audio['data'] ?? null;

        if (! is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from Gemini API.');
        }

        $pcm = base64_decode($encodedAudio, true);

        if ($pcm === false) {
            throw new RuntimeException('Gemini returned invalid audio data.');
        }

        return new AudioResponse(
            base64_encode($this->pcmToWav($pcm, $audio['sample_rate'] ?? 24000, $audio['channels'] ?? 1)),
            $this->extractUsage($data),
            new Meta($provider->name(), $model),
            'audio/wav',
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
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
        $audioBlock = [
            'type' => 'audio',
            'mime_type' => $audio->mimeType() ?? 'audio/mp3',
            'data' => base64_encode($audio->content()),
        ];

        // Only the transcribe models accept a transcription config; the rest are asked in prose...
        return str_contains($model, 'transcribe')
            ? $this->transcribeWithConfig($provider, $model, $audioBlock, $language, $diarize, $timeout, $providerOptions)
            : $this->transcribeWithPrompt($provider, $model, $audioBlock, $language, $diarize, $timeout, $providerOptions);
    }

    /**
     * Transcribe the given audio using the Gemini transcription config.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    protected function transcribeWithConfig(
        TranscriptionProvider $provider,
        string $model,
        array $audioBlock,
        ?string $language,
        bool $diarize,
        int $timeout,
        array $providerOptions,
    ): TranscriptionResponse {
        $transcriptionConfig = Arr::whereNotNull([
            'language_codes' => $language !== null ? [$language] : null,
            'mode' => $diarize ? [
                'type' => 'verbatim',
                'diarization_mode' => 'speaker',
                'timestamp_granularities' => ['word'],
            ] : null,
        ]);

        $generationConfig = array_replace_recursive(
            $providerOptions['generation_config'] ?? [],
            filled($transcriptionConfig) ? ['transcription_config' => $transcriptionConfig] : [],
        );

        $body = array_merge(['store' => false], $providerOptions, array_filter([
            'model' => $model,
            'input' => [$audioBlock],
            'generation_config' => $generationConfig ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->json();

        $steps = $data['steps'] ?? [];

        return new TranscriptionResponse(
            trim($this->extractText($steps)),
            $this->speakerSegments($steps),
            TranscriptionUsage::from($this->extractUsage($data)),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Group the word annotations Gemini returns into one segment per speaker turn.
     *
     * @return Collection<int, TranscriptionSegment>
     */
    protected function speakerSegments(array $steps): Collection
    {
        $segments = new Collection;

        foreach ($this->wordAnnotations($steps) as $word) {
            $text = (string) ($word['text'] ?? '');
            $speaker = (string) ($word['speaker'] ?? '');
            $last = $segments->last();

            if ($last instanceof TranscriptionSegment && $last->speaker === $speaker) {
                $last->text = trim($last->text.' '.$text);
                $last->endSeconds = $this->offsetToSeconds($word['end_offset'] ?? '');

                continue;
            }

            $segments->push(new TranscriptionSegment(
                $text,
                $speaker,
                $this->offsetToSeconds($word['start_offset'] ?? ''),
                $this->offsetToSeconds($word['end_offset'] ?? ''),
            ));
        }

        return $segments;
    }

    /**
     * Get the word annotations Gemini attached to the transcribed text.
     */
    protected function wordAnnotations(array $steps): array
    {
        return (new Collection($steps))
            ->flatMap(fn (array $step): array => $step['content'] ?? [])
            ->flatMap(fn ($block): array => is_array($block) ? ($block['annotations'] ?? []) : [])
            ->filter(fn ($annotation): bool => is_array($annotation) && ($annotation['type'] ?? '') === 'word_info')
            ->all();
    }

    /**
     * Convert a Gemini duration offset, such as "1.500s", to seconds.
     */
    protected function offsetToSeconds(string $offset): float
    {
        return $this->timestampToSeconds(rtrim($offset, 's'));
    }

    /**
     * Transcribe the given audio by asking a general purpose model for the text.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    protected function transcribeWithPrompt(
        TranscriptionProvider $provider,
        string $model,
        array $audioBlock,
        ?string $language,
        bool $diarize,
        int $timeout,
        array $providerOptions,
    ): TranscriptionResponse {
        if ($diarize) {
            $prompt = $language !== null
                ? "Transcribe this audio with timestamps in {$language}. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time."
                : 'Transcribe this audio with timestamps. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)->post('interactions', array_merge(['store' => false], $providerOptions, [
                    'model' => $model,
                    'input' => [['type' => 'text', 'text' => $prompt], $audioBlock],
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'transcript' => ['type' => 'string'],
                                'segments' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'text' => ['type' => 'string'],
                                            'start_time' => ['type' => 'string'],
                                            'end_time' => ['type' => 'string'],
                                        ],
                                        'required' => ['text', 'start_time', 'end_time'],
                                    ],
                                ],
                            ],
                            'required' => ['transcript', 'segments'],
                        ],
                    ],
                ])),
            );

            $payload = $response->json();

            $data = json_decode($this->extractText($payload['steps'] ?? []) ?: '{}', true);

            $text = $data['transcript'] ?? '';

            $segments = (new Collection($data['segments'] ?? []))->map(fn ($seg): TranscriptionSegment => new TranscriptionSegment(
                $seg['text'],
                '',
                $this->timestampToSeconds($seg['start_time'] ?? '0:00'),
                $this->timestampToSeconds($seg['end_time'] ?? '0:00'),
            ));
        } else {
            $prompt = $language !== null
                ? "Transcribe this audio. Output only the transcription in {$language}."
                : 'Transcribe this audio. Output only the transcription text.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)->post('interactions', array_merge(['store' => false], $providerOptions, [
                    'model' => $model,
                    'input' => [['type' => 'text', 'text' => $prompt], $audioBlock],
                ])),
            );

            $payload = $response->json();

            $text = $this->extractText($payload['steps'] ?? []);

            $segments = new Collection;
        }

        return new TranscriptionResponse(
            trim((string) $text),
            $segments,
            TranscriptionUsage::from($this->extractUsage($payload)),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert a timestamp string to seconds.
     */
    protected function timestampToSeconds(string $timestamp): float
    {
        $timestamp = str_replace(',', '.', trim($timestamp));

        if (preg_match('/^\d+(?:\.\d+)?$/', $timestamp) === 1) {
            return (float) $timestamp;
        }

        if (preg_match('/^\d+(?::\d+){1,2}(?:\.\d+)?$/', $timestamp) !== 1) {
            return 0.0;
        }

        $parts = array_reverse(explode(':', $timestamp));

        return (float) $parts[0]
            + ((float) ($parts[1] ?? 0)) * 60
            + ((float) ($parts[2] ?? 0)) * 3600;
    }
}
