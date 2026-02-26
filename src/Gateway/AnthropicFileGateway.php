<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class AnthropicFileGateway implements FileGateway
{
    use Concerns\HandlesRateLimiting;
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'files-api-2025-04-14',
        ])->get("https://api.anthropic.com/v1/files/{$fileId}")->throw());

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

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'files-api-2025-04-14',
        ])
            ->attach('file', $content, $name, ['Content-Type' => $mime])
            ->post('https://api.anthropic.com/v1/files')
            ->throw());

        return new StoredFileResponse($response->json('id'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'files-api-2025-04-14',
        ])
            ->delete("https://api.anthropic.com/v1/files/{$fileId}")
            ->throw());
    }
}
