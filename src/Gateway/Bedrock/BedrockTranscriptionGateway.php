<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;

class BedrockTranscriptionGateway implements TranscriptionGateway
{
    use HandlesRateLimiting;

    /**
     * Generate text from the given audio using AWS Bedrock.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30
    ): TranscriptionResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        $audioContent = base64_encode($audio->content());
        $mimeType = $audio->mimeType();

        // Prepare request body for transcription
        $requestBody = [
            'audio' => $audioContent,
            'mimeType' => $mimeType,
        ];

        if ($language) {
            $requestBody['language'] = $language;
        }

        if ($diarize) {
            $requestBody['enableDiarization'] = true;
        }

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $client->invokeModel([
                'modelId' => $model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($requestBody),
            ])
        );

        $result = json_decode($response->get('body')->getContents(), true);

        // Parse transcription result
        $text = $result['transcript'] ?? $result['text'] ?? '';
        $segments = $this->parseSegments($result, $diarize);

        return new TranscriptionResponse(
            $text,
            $segments,
            new Usage,
            new Meta($provider->name(), $model)
        );
    }

    /**
     * Parse transcription segments from the response.
     */
    protected function parseSegments(array $result, bool $diarize): Collection
    {
        if (! $diarize || ! isset($result['segments'])) {
            return new Collection;
        }

        return (new Collection($result['segments']))->map(function ($segment) {
            return new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? $segment['speakerId'] ?? '',
                $segment['start'] ?? 0.0,
                $segment['end'] ?? 0.0,
            );
        });
    }

    /**
     * Create a Bedrock Runtime client.
     */
    protected function createBedrockClient(TranscriptionProvider $provider, int $timeout): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();
        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => '2023-09-30',
            'http' => ['timeout' => $timeout],
        ];

        // Handle different authentication methods
        if (! empty($credentials['bearer_token'])) {
            $clientConfig['credentials'] = [
                'token' => $credentials['bearer_token'],
            ];
        } elseif (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            $clientConfig['credentials'] = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $clientConfig['credentials']['token'] = $credentials['session_token'];
            }
        } elseif ($config['use_default_credential_provider'] ?? true) {
            // Use AWS default credential chain
        }

        return new BedrockRuntimeClient($clientConfig);
    }
}
