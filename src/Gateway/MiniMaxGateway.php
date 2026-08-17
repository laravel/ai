<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use RuntimeException;

class MiniMaxGateway implements AudioGateway
{
    use Concerns\CreatesClient;
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
        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider, $timeout)
            ->post('t2a_v2', [
                'model' => $model,
                'text' => $text,
                'stream' => false,
                'output_format' => 'hex',
                'voice_setting' => [
                    'voice_id' => match ($voice) {
                        'default-male' => 'English_Persuasive_Man',
                        'default-female' => 'English_Graceful_Lady',
                        default => $voice,
                    },
                ],
                'audio_setting' => [
                    'format' => 'mp3',
                ],
            ])->throw());

        $data = $response->json();

        if ((int) ($data['base_resp']['status_code'] ?? 0) !== 0) {
            throw new RuntimeException('MiniMax API returned an error: '.($data['base_resp']['status_msg'] ?? 'Unknown error.'));
        }

        $encodedAudio = $data['data']['audio'] ?? null;

        if (! is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from MiniMax API.');
        }

        if (strlen($encodedAudio) % 2 !== 0 || ! ctype_xdigit($encodedAudio)) {
            throw new RuntimeException('MiniMax returned invalid audio data.');
        }

        return new AudioResponse(
            base64_encode((string) hex2bin($encodedAudio)),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Get an HTTP client for the MiniMax API.
     */
    protected function client(AudioProvider $provider, int $timeout = 30): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            ['Authorization' => 'Bearer '.$provider->providerCredentials()['key']],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
            false,
        );
    }

    /**
     * Get the base URL for the MiniMax API.
     */
    protected function baseUrl(AudioProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.minimax.io/v1', '/');
    }
}
