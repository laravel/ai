<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Gateway\VoiceGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Contracts\Providers\VoiceProvider;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\Data\Voice;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Responses\VoicesResponse;

class ElevenLabsGateway implements AudioGateway, TranscriptionGateway, VoiceGateway
{
    use Concerns\CreatesClient;
    use Concerns\HandlesFailoverErrors;

    /**
     * Generate audio from the given text.
     *
     * @param  array<string, mixed>  $providerOptions
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
        $voice = match ($voice) {
            'default-male' => 'onwK4e9ZLuTAKqWW03F9',
            'default-female' => 'XrExE9yKIg1WjnnlVkGX',
            default => $voice,
        };

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->post('text-to-speech/'.$voice, array_merge($providerOptions, [
                'model_id' => $model,
                'text' => $text,
            ]))->throw());

        return new AudioResponse(
            base64_encode((string) $response),
            new Meta($provider->name(), $model),
            'audio/mpeg'
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
                'language_code' => $language,
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
     * List the voices available for audio generation.
     */
    public function listVoices(
        VoiceProvider $provider,
        int $timeout = 30,
    ): VoicesResponse {
        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->get('voices')->throw());

        $voices = (new Collection($response->json('voices') ?? []))
            ->map(fn (array $voice): Voice => new Voice(
                $voice['voice_id'],
                $voice['name'] ?? $voice['voice_id'],
                $voice['labels']['gender'] ?? null,
                (new Collection($voice['verified_languages'] ?? []))->pluck('language')->filter()->unique()->values()->all(),
            ))->all();

        return new VoicesResponse($voices, new Meta($provider->name()));
    }

    /**
     * Get an HTTP client for the ElevenLabs API.
     */
    protected function client(AudioProvider|TranscriptionProvider|VoiceProvider $provider, int $timeout = 30): PendingRequest
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
    protected function baseUrl(AudioProvider|TranscriptionProvider|VoiceProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.elevenlabs.io/v1', '/');
    }
}
