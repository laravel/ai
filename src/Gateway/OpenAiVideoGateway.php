<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Responses\Data\GeneratedVideo;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\VideoResponse;
use RuntimeException;

class OpenAiVideoGateway implements VideoGateway
{
    protected const string BASE_URL = 'https://api.openai.com/v1';

    /**
     * {@inheritdoc}
     */
    public function generateVideo(
        VideoProvider $provider,
        string $model,
        string $prompt,
        ?string $seconds = null,
        ?string $size = null,
        ?int $timeout = null,
        ?int $pollIntervalSeconds = null,
    ): VideoResponse {
        $key = $provider->providerCredentials()['key'] ?? null;

        if (empty($key)) {
            throw new RuntimeException('OpenAI API key is missing for video generation.');
        }

        $seconds = $seconds ?? '4';
        $size = $size ?? '1280x720';
        $pollIntervalSeconds = max(1, $pollIntervalSeconds ?? 2);
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        $create = Http::withToken($key)
            ->timeout(120)
            ->asMultipart()
            ->post(self::BASE_URL.'/videos', [
                ['name' => 'prompt', 'contents' => $prompt],
                ['name' => 'model', 'contents' => $model],
                ['name' => 'seconds', 'contents' => (string) $seconds],
                ['name' => 'size', 'contents' => $size],
            ]);

        if (! $create->successful()) {
            throw new RuntimeException('OpenAI video create failed: '.$create->body());
        }

        $videoId = $create->json('id');

        if (! is_string($videoId) || $videoId === '') {
            throw new RuntimeException('OpenAI video create response missing id.');
        }

        $status = 'queued';
        $progress = 0;

        while (in_array($status, ['queued', 'in_progress'], true)) {
            if ($deadline !== null && microtime(true) > $deadline) {
                throw new RuntimeException('OpenAI video generation timed out after '.$timeout.' seconds.');
            }

            sleep($pollIntervalSeconds);

            $poll = Http::withToken($key)
                ->timeout(60)
                ->get(self::BASE_URL.'/videos/'.$videoId);

            if (! $poll->successful()) {
                throw new RuntimeException('OpenAI video status failed: '.$poll->body());
            }

            $status = (string) $poll->json('status', 'failed');
            $progress = (int) $poll->json('progress', 0);

            if ($status === 'failed') {
                $message = $poll->json('error.message') ?? $poll->body();

                throw new RuntimeException('OpenAI video generation failed: '.$message);
            }
        }

        if ($status !== 'completed') {
            throw new RuntimeException('OpenAI video ended in unexpected status: '.$status);
        }

        $binary = Http::withToken($key)
            ->timeout(300)
            ->withHeaders(['Accept' => 'video/mp4'])
            ->get(self::BASE_URL.'/videos/'.$videoId.'/content');

        if (! $binary->successful()) {
            throw new RuntimeException('OpenAI video download failed: '.$binary->body());
        }

        $mime = $binary->header('Content-Type') ?: 'video/mp4';

        return new VideoResponse(
            collect([new GeneratedVideo($binary->body(), $mime)]),
            new Usage,
            new Meta(provider: $provider->name(), model: $model),
            remoteId: $videoId,
        );
    }
}
