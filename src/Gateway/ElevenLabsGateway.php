<?php

namespace Laravel\Ai\Gateway;

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
    use Concerns\HandlesRateLimiting;

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null): AudioResponse
    {
        $voice = match ($voice) {
            'default-male' => 'onwK4e9ZLuTAKqWW03F9',
            'default-female' => 'XrExE9yKIg1WjnnlVkGX',
            default => $voice,
        };

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'xi-api-key' => $provider->providerCredentials()['key'],
        ])->post('https://api.elevenlabs.io/v1/text-to-speech/'.$voice, [
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
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $context = null,
    ): TranscriptionResponse {
        $audioContent = match (true) {
            $audio instanceof TranscribableAudio => $audio->content(),
        };

        $mimeType = match (true) {
            $audio instanceof TranscribableAudio => $audio->mimeType(),
        };

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'xi-api-key' => $provider->providerCredentials()['key'],
        ])->attach(
            'file', $audioContent, 'file', ['Content-Type' => $mimeType],
        )->post('https://api.elevenlabs.io/v1/speech-to-text', [
            'model_id' => $model,
            'language' => $language,
            'diarize' => $diarize ? 'true' : 'false',
        ])->throw());

        $response = $response->json();

        $segments = $diarize
            ? ($response['words'] ?? [])
            : [];

        return new TranscriptionResponse(
            $response['text'],
            collect($segments)->map(function ($segment) {
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
}
