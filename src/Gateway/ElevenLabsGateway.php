<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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
    use Concerns\HandlesFailoverErrors;

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
            'default-male' => 'onwK4e9ZLuTAKqWW03F9',
            'default-female' => 'XrExE9yKIg1WjnnlVkGX',
            default => $voice,
        };

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->post('text-to-speech/'.$voice, [
                'model_id' => $model,
                'text' => $text,
            ])->throw());

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
            ->attach('file', $audio->content(), 'file', ['Content-Type' => $audio->mimeType()])
            ->post('speech-to-text', array_merge($providerOptions, [
                'model_id' => $model,
                'language' => $language,
                'diarize' => $diarize ? 'true' : 'false',
            ]))->throw());

        $response = $response->json();

        $segments = $diarize
            ? ($response['words'] ?? [])
            : [];

        return new TranscriptionResponse(
            $response['text'],
            (new Collection($segments))->map(function ($segment) {
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
        return Http::baseUrl($this->baseUrl($provider))
            ->withHeaders(['xi-api-key' => $provider->providerCredentials()['key']])
            ->timeout($timeout);
    }

    /**
     * Get the base URL for the ElevenLabs API.
     */
    protected function baseUrl(AudioProvider|TranscriptionProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.elevenlabs.io/v1', '/');
    }
}
