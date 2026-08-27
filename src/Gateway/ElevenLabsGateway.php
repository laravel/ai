<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;

class ElevenLabsGateway implements AudioGateway, TranscriptionGateway
{
    use Concerns\CreatesClient;
    use Concerns\HandlesFailoverErrors;
    use Concerns\ResolvesAudioMimeTypes;

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
            'default-male' => 'onwK4e9ZLuTAKqWW03F9',
            'default-female' => 'XrExE9yKIg1WjnnlVkGX',
            default => $voice,
        };

        $url = 'text-to-speech/'.$voice.($format ? '?output_format='.$format : '');

        $payload = array_filter([
            'model_id' => $model,
            'text' => $text,
            'voice_settings' => $speed !== null ? ['speed' => $speed] : null,
        ]);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->post($url, $payload)->throw());

        return new AudioResponse(
            base64_encode((string) $response),
            new Meta($provider->name(), $model),
            $format ? $this->audioResponseMimeType($format) : 'audio/mpeg'
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
        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->attach('file', $audio->content(), 'file', array_filter(['Content-Type' => $audio->mimeType()]))
            ->post('speech-to-text', array_merge($providerOptions, array_filter([
                'model_id' => $model,
                'language' => $language,
                'diarize' => $diarize ? 'true' : 'false',
            ])))->throw());

        $response = $response->json();

        $segments = $diarize
            ? ($response['words'] ?? [])
            : [];

        return new TranscriptionResponse(
            $response['text'],
            (new Collection($segments))->map(function (array $segment) {
                if ($segment['type'] !== 'word') {
                    return;
                }

                return new TranscriptionSegment(
                    $segment['text'],
                    $segment['speaker_id'] ?? '',
                    $segment['start'],
                    $segment['end'],
                );
            })->filter()->values(),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the ElevenLabs API.
     */
    protected function client(AudioProvider|TranscriptionProvider $provider, int $timeout = 30): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            array_filter(['xi-api-key' => $provider->providerCredentials()['key']]),
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
            false,
        );
    }

    /**
     * Get the base URL for the ElevenLabs API.
     */
    protected function baseUrl(AudioProvider|TranscriptionProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.elevenlabs.io/v1', '/');
    }
}
