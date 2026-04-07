<?php

namespace Laravel\Ai\Gateway\Anthropic;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class AnthropicFileGateway implements FileGateway
{
    use Concerns\CreatesAnthropicClient;
    use HandlesRateLimiting;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)->get("files/{$fileId}"),
        );

        return new FileResponse(
            id: $response->json('id'),
            mimeType: $response->json('mime_type'),
        );
    }

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse {
        [$content, $mime, $name] = $this->prepareStorableFile($file);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files'),
        );

        return new StoredFileResponse($response->json('id'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete("files/{$fileId}"),
        );
    }

    /**
     * Get an HTTP client for the Anthropic Files API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withHeaders([
                'x-api-key' => $provider->providerCredentials()['key'],
                'anthropic-version' => $provider->additionalConfiguration()['version'] ?? '2023-06-01',
                'anthropic-beta' => 'files-api-2025-04-14',
            ])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
