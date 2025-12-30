<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Attachments\Audio;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;

class ElevenLabsGateway implements AudioGateway, TranscriptionGateway
{
    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        Provider $provider,
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

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $provider->providerCredentials()['key'],
            ])->post('https://api.elevenlabs.io/v1/text-to-speech/'.$voice, [
                'model_id' => $model,
                'text' => $text,
            ])
                ->throw();
        } catch (RequestException $e) {
            if ($e->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

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
        Provider $provider,
        string $model,
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse {
        $audioContent = match (true) {
            $audio instanceof TranscribableAudio => base64_decode($audio->toBase64ForTranscription()),
            $audio instanceof UploadedFile => $audio->get(),
        };

        $mimeType = match (true) {
            $audio instanceof TranscribableAudio => $audio->mimeTypeForTranscription(),
            $audio instanceof UploadedFile => $audio->getClientMimeType(),
        };

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $provider->providerCredentials()['key'],
            ])->attach(
                'file', $audioContent, 'file', ['Content-Type' => $mimeType],
            )->post('https://api.elevenlabs.io/v1/speech-to-text', [
                'model_id' => $model,
                'language' => $language,
                'diarize' => $diarize,
            ])->throw();
        } catch (RequestException $e) {
            if ($e->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        $response = $response->json();

        return new TranscriptionResponse(
            $response['text'],
            collect($response['words'] ?? [])->map(function ($segment) {
                if ($segment['type'] !== 'word') {
                    return;
                }

                return new TranscriptionSegment(
                    $segment['text'],
                    $segment['speaker_id'],
                    $segment['start'],
                    $segment['end'],
                );
            })->filter()->values(),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
