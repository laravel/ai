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

class OpenAiFileGateway implements FileGateway
{
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/files/{$fileId}")
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
            mime: null,
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
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('https://api.openai.com/v1/files', [
                    'purpose' => 'user_data',
                ])
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
            Http::withToken($provider->providerCredentials()['key'])
                ->delete("https://api.openai.com/v1/files/{$fileId}")
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
