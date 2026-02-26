<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class GeminiFileGateway implements FileGateway
{
    use Concerns\HandlesRateLimiting;
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $fileId = str_starts_with($fileId, 'files/') ? $fileId : "files/{$fileId}";

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->get("https://generativelanguage.googleapis.com/v1beta/{$fileId}")->throw());

        return new FileResponse(
            id: $response->json('name'),
            mimeType: $response->json('mimeType'),
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
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->attach(
            'file', $content, $name, ['Content-Type' => $mime]
        )->post('https://generativelanguage.googleapis.com/upload/v1beta/files', [
            'file' => ['display_name' => $name],
        ])->throw());

        return new StoredFileResponse($response->json('file.name'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $fileId = str_starts_with($fileId, 'files/') ? $fileId : "files/{$fileId}";

        $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->delete("https://generativelanguage.googleapis.com/v1beta/{$fileId}")->throw());
    }
}
