<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Responses\Data\GeneratedVideo;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\VideoResponse;
use RuntimeException;

class OpenAiVideoGateway implements VideoGateway
{
    use Concerns\HandlesFailoverErrors;
    use OpenAi\Concerns\CreatesOpenAiClient;

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
        $seconds ??= '4';
        $size ??= '1280x720';
        $pollIntervalSeconds = max(1, $pollIntervalSeconds ?? 2);
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        // Bound every request's timeout by the time remaining on the single overall deadline, falling back to a per-request default when no timeout was given.
        $remaining = fn (int $default): int => $deadline === null
            ? $default
            : max(1, min($default, (int) ceil($deadline - microtime(true))));

        return $this->withErrorHandling($provider->name(), function () use (
            $provider, $model, $prompt, $seconds, $size, $deadline, $pollIntervalSeconds, $remaining, $timeout
        ): VideoResponse {
            $create = $this->client($provider, $remaining(120))
                ->asMultipart()
                ->post('videos', [
                    ['name' => 'prompt', 'contents' => $prompt],
                    ['name' => 'model', 'contents' => $model],
                    ['name' => 'seconds', 'contents' => (string) $seconds],
                    ['name' => 'size', 'contents' => $size],
                ]);

            $videoId = $create->json('id');

            if (! is_string($videoId) || $videoId === '') {
                throw new RuntimeException('OpenAI video create response missing id.');
            }

            $status = (string) $create->json('status', 'queued');

            while (in_array($status, ['queued', 'in_progress'], true)) {
                if ($deadline !== null && microtime(true) > $deadline) {
                    throw new RuntimeException('OpenAI video generation timed out after '.$timeout.' seconds.');
                }

                Sleep::for($pollIntervalSeconds)->seconds();

                $poll = $this->client($provider, $remaining(60))->get('videos/'.$videoId);

                $status = (string) $poll->json('status', 'failed');

                if ($status === 'failed') {
                    $message = $poll->json('error.message') ?? $poll->body();

                    throw new RuntimeException('OpenAI video generation failed: '.$message);
                }
            }

            if ($status !== 'completed') {
                throw new RuntimeException('OpenAI video ended in unexpected status: '.$status);
            }

            $binary = $this->client($provider, $remaining(300))
                ->withHeaders(['Accept' => 'video/mp4'])
                ->get('videos/'.$videoId.'/content');

            $mime = $binary->header('Content-Type') ?: 'video/mp4';

            return new VideoResponse(
                collect([new GeneratedVideo($binary->body(), $mime)]),
                new Usage,
                new Meta(provider: $provider->name(), model: $model),
                remoteId: $videoId,
            );
        });
    }
}
