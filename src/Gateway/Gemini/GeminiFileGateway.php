<?php

namespace Laravel\Ai\Gateway\Gemini;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class GeminiFileGateway implements FileGateway
{
    use HandlesFailoverErrors;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $fileId = str_starts_with($fileId, 'files/') ? $fileId : "files/{$fileId}";

        $response = $this->withErrorHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->get($this->baseUrl($provider)."/{$fileId}")->throw());

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

        $uploadUrl = str_replace('/v1beta', '/upload/v1beta', $this->baseUrl($provider));

        $response = $this->withErrorHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->attach(
            'file', $content, $name, ['Content-Type' => $mime]
        )->post("{$uploadUrl}/files", [
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

        $this->withErrorHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->delete($this->baseUrl($provider)."/{$fileId}")->throw());
    }

    /**
     * Get the base URL for the Gemini API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');
    }
}
