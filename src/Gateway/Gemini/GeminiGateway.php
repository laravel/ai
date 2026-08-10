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
            fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", $body),
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
                ->post("models/{$model}:streamGenerateContent?alt=sse", $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
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
            ->filter(fn ($part): bool => isset($part['inlineData']))
            ->values()
            ->map(fn ($part): GeneratedImage => new GeneratedImage(
                $part['inlineData']['data'],
                $part['inlineData']['mimeType'],
            ));

        return new ImageResponse(
            $images,
            $this->extractUsage($data),
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
            $data['usageMetadata']['promptTokenCount'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate audio from the given text.
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
    ): AudioResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $instructions !== null && trim($instructions) !== ''
                            ? trim($instructions)."\n\n".$text
                            : $text,
                    ]],
                ]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => match ($voice) {
                                    'default-female' => 'Kore',
                                    'default-male' => 'Puck',
                                    default => $voice,
                                },
                            ],
                        ],
                    ],
                ],
            ]),
        );

        $data = $response->json();

        $encodedAudio = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

        if (! is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from Gemini API.');
        }

        $pcm = base64_decode($encodedAudio, true);

        if ($pcm === false) {
            throw new RuntimeException('Gemini returned invalid audio data.');
        }

        return new AudioResponse(
            base64_encode($this->pcmToWav($pcm)),
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
        $inlineData = ['inlineData' => [
            'mimeType' => $audio->mimeType() ?? 'audio/mp3',
            'data' => base64_encode($audio->content()),
        ]];

        if ($diarize) {
            $prompt = $language !== null
                ? "Transcribe this audio with timestamps in {$language}. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time."
                : 'Transcribe this audio with timestamps. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", array_merge($providerOptions, [
                    'contents' => [[
                        'parts' => [['text' => $prompt], $inlineData],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'transcript' => ['type' => 'STRING'],
                                'segments' => [
                                    'type' => 'ARRAY',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'text' => ['type' => 'STRING'],
                                            'start_time' => ['type' => 'STRING'],
                                            'end_time' => ['type' => 'STRING'],
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

            $data = json_decode($response->json('candidates.0.content.parts.0.text') ?? '{}', true);

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
                fn () => $this->client($provider, $timeout)->post("models/{$model}:generateContent", array_merge($providerOptions, [
                    'contents' => [[
                        'parts' => [['text' => $prompt], $inlineData],
                    ]],
                ])),
            );

            $text = $response->json('candidates.0.content.parts.0.text') ?? '';

            $segments = new Collection;
        }

        $usageMeta = $response->json('usageMetadata') ?? [];

        return new TranscriptionResponse(
            trim((string) $text),
            $segments,
            new Usage(
                promptTokens: $usageMeta['promptTokenCount'] ?? 0,
                completionTokens: $usageMeta['candidatesTokenCount'] ?? 0,
                reasoningTokens: $usageMeta['thoughtsTokenCount'] ?? 0,
            ),
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
