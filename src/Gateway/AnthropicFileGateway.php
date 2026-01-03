<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class AnthropicFileGateway implements FileGateway
{
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $provider->providerCredentials()['key'],
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'files-api-2025-04-14',
            ])
                ->get("https://api.anthropic.com/v1/files/{$fileId}")
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return new FileResponse(
            id: $response->json('id'),
            content: null,
            mimeType: $response->json('mime_type'),
        );
    }

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile|UploadedFile|string $file,
        ?string $mime = null,
    ): StoredFileResponse {
        [$content, $mime, $name] = $this->prepareStorableFile($file, $mime);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $provider->providerCredentials()['key'],
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'files-api-2025-04-14',
            ])
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('https://api.anthropic.com/v1/files')
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return new StoredFileResponse($response->json('id'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        try {
            Http::withHeaders([
                'x-api-key' => $provider->providerCredentials()['key'],
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'files-api-2025-04-14',
            ])
                ->delete("https://api.anthropic.com/v1/files/{$fileId}")
                ->throw();
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
