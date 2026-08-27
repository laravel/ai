<?php

namespace Laravel\Ai\Gateway\OpenAi;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioMimeTypes;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class OpenAiGateway implements Gateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenAiClient;
    use Concerns\HandlesTextGeneration;
    use Concerns\HandlesTextSteps;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;
    use ResolvesAudioFilenames;
    use ResolvesAudioMimeTypes;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
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
        $hasAttachments = filled($attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $hasAttachments
                ? $this->sendImageEditRequest($provider, $model, $prompt, $attachments, $size, $quality, $timeout)
                : $this->sendImageGenerationRequest($provider, $model, $prompt, $size, $quality, $timeout),
        );

        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            $this->extractUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Send an image generation request.
     */
    protected function sendImageGenerationRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        ?string $size,
        ?string $quality,
        ?int $timeout,
    ) {
        return $this->client($provider, $timeout ?? 120)->post('images/generations', [
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...(str_starts_with($model, 'gpt-image')
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ]);
    }

    /**
     * Send an image edit request with attachments.
     */
    protected function sendImageEditRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments,
        ?string $size,
        ?string $quality,
        ?int $timeout,
    ) {
        $request = $this->client($provider, $timeout ?? 120);

        $isGptImage = str_starts_with($model, 'gpt-image');
        $field = $isGptImage ? 'image[]' : 'image';

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.$attachment::class.']'
                );
            }

            $content = match (true) {
                $attachment instanceof LocalImage => file_get_contents($attachment->path),
                $attachment instanceof StoredImage => Storage::disk($attachment->disk)->get($attachment->path),
                $attachment instanceof UploadedFile => $attachment->get(),
                default => throw new InvalidArgumentException('Unsupported image attachment type ['.$attachment::class.']'),
            };

            $request = $request->attach($field, $content, 'image.png');
        }

        return $request->post('images/edits', array_filter([
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...($isGptImage
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ]));
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
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        $format ??= 'mp3';

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
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
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
     *
     * @throws LogicException if diarization is requested together with the `prompt` provider option.
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
        if ($diarize && filled($providerOptions['prompt'] ?? null)) {
            throw new LogicException('OpenAI does not support the `prompt` option for diarized transcriptions.');
        }

        if ($provider->driver() === 'openai' && ! $diarize) {
            $model = str_replace('-diarize', '', $model);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new Usage(
                Arr::get($data, 'usage.input_tokens', 0),
                Arr::get($data, 'usage.output_tokens', 0),
            ),
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
