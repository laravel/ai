<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;

class MurfGateway implements AudioGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Base URL for the Murf API.
     */
    protected string $baseUrl = 'https://api.murf.ai/v1';

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
        $voiceId = $this->resolveVoiceId($voice);

        $response = $this->withRateLimitHandling($provider->name(), fn() => Http::withHeaders([
            'api-key' => $provider->providerCredentials()['key'],
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/speech/generate', [
            'text' => $text,
            'voiceId' => $voiceId,
            'format' => 'MP3',
            'encodeAsBase64' => true,
            'modelVersion' => $model,
        ])->throw());

        $body = $response->json();
        $encodedAudio = $body['encodedAudio'] ?? '';

        return new AudioResponse(
            $encodedAudio,
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Resolve the Murf voice ID from the given voice name or identifier.
     */
    protected function resolveVoiceId(string $voice): string
    {
        return match ($voice) {
            'default-male' => 'en-US-george',
            'default-female' => 'en-US-natalie',
            default => $voice,
        };
    }
}
