<?php

namespace Laravel\Ai\Gateway\Infomaniak;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\TranscriptionResponse;

class InfomaniakTranscriptionGateway implements TranscriptionGateway
{
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
    ): TranscriptionResponse {
        $config = $provider->config();

        $multipart = [
            ['name' => 'model', 'contents' => $model],
            ['name' => 'file', 'contents' => $audio->content(), 'filename' => 'audio.mp3'],
        ];

        if ($language !== null) {
            $multipart[] = ['name' => 'language', 'contents' => $language];
        }

        if ($diarize) {
            $multipart[] = ['name' => 'diarize', 'contents' => 'true'];
        }

        $response = Http::withToken($config['key'] ?? '')
            ->timeout($timeout)
            ->attach('file', $audio->content(), 'audio.mp3')
            ->post(rtrim($config['url'] ?? 'https://api.infomaniak.com/1/ai', '/').'/openai/audio/transcriptions', [
                'model' => $model,
                'language' => $language,
                'diarize' => $diarize ? 'true' : null,
            ]);

        $data = $response->json();

        if ($response->failed()) {
            throw new \Laravel\Ai\Exceptions\AiException(sprintf(
                'Infomaniak Error: %s',
                $data['error']['message'] ?? 'Unknown error'
            ));
        }

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect(),
            new \Laravel\Ai\Responses\Data\Usage(0, $data['usage']['total_tokens'] ?? 0),
            new \Laravel\Ai\Responses\Data\Meta($provider->name(), $model),
            $data['language'] ?? null,
        );
    }
}
