<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;

class DeepgramGateway implements AudioGateway, TranscriptionGateway
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
        $model = match ($voice) {
            'default-male' => 'aura-2-perseus-en',
            'default-female' => 'aura-2-thalia-en',
            default => $voice,
        };

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'Authorization' => 'Token '.$provider->providerCredentials()['key'],
        ])->withBody(json_encode(['text' => $text]), 'application/json')
            ->post('https://api.deepgram.com/v1/speak?model='.$model)
            ->throw());

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
    ): TranscriptionResponse {
        $audioContent = match (true) {
            $audio instanceof TranscribableAudio => $audio->content(),
        };

        $mimeType = match (true) {
            $audio instanceof TranscribableAudio => $audio->mimeType(),
        };

        $query = array_filter([
            'model' => $model,
            'language' => $language,
            'diarize' => $diarize ? 'true' : null,
            'punctuate' => 'true',
        ]);

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'Authorization' => 'Token '.$provider->providerCredentials()['key'],
            'Content-Type' => $mimeType,
        ])->withBody($audioContent, $mimeType)
            ->post('https://api.deepgram.com/v1/listen?'.http_build_query($query))
            ->throw());

        $response = $response->json();

        $transcript = $response['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';

        $segments = $diarize
            ? ($response['results']['channels'][0]['alternatives'][0]['words'] ?? [])
            : [];

        return new TranscriptionResponse(
            $transcript,
            (new Collection($segments))->map(function ($segment) {
                return new TranscriptionSegment(
                    $segment['word'],
                    (string) ($segment['speaker'] ?? ''),
                    $segment['start'],
                    $segment['end'],
                );
            })->values(),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
