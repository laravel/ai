<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class GeminiFileGateway implements FileGateway
{
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $fileId = str_starts_with($fileId, 'files/') ? $fileId : "files/{$fileId}";

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $provider->providerCredentials()['key'],
            ])->get("https://generativelanguage.googleapis.com/v1beta/{$fileId}")->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return new FileResponse(
            id: $response->json('name'),
            content: null,
            mime: $response->json('mimeType'),
        );
    }

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
        ?string $mime = null,
        ?string $name = null,
    ): StoredFileResponse {
        [$content, $mime, $name] = $this->prepareStorableFile($file, $mime, $name);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $provider->providerCredentials()['key'],
            ])->attach(
                'file', $content, $name, ['Content-Type' => $mime]
            )->post('https://generativelanguage.googleapis.com/upload/v1beta/files', [
                'file' => ['display_name' => $name],
            ])->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return new StoredFileResponse($response->json('file.name'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $fileId = str_starts_with($fileId, 'files/') ? $fileId : "files/{$fileId}";

        try {
            Http::withHeaders([
                'x-goog-api-key' => $provider->providerCredentials()['key'],
            ])->delete("https://generativelanguage.googleapis.com/v1beta/{$fileId}")->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }
    }
}
