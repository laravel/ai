<?php

namespace Laravel\Ai\Gateway;

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

class ChutesAudioGateway implements AudioGateway, TranscriptionGateway
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
        ?string $instructions = null,
    ): AudioResponse {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout(120)
                ->post($provider->additionalConfiguration()['tts_url'] ?? 'https://chutes-kokoro.chutes.ai/speak', [
                    'text' => $text,
                ])
                ->throw()
        );

        return new AudioResponse(
            base64_encode((string) $response),
            new Meta($provider->name(), $model),
            'audio/wav',
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
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout(120)
                ->post($provider->additionalConfiguration()['stt_url'] ?? 'https://chutes-whisper-large-v3.chutes.ai/transcribe', [
                    'audio_b64' => base64_encode($audio->content()),
                ])
                ->throw()
        );

        $segments = $response->json();

        $text = collect($segments)->pluck('text')->implode(' ');

        return new TranscriptionResponse(
            $text,
            collect($segments)->map(fn ($segment) => new TranscriptionSegment(
                $segment['text'],
                '',
                $segment['start'],
                $segment['end'],
            )),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
