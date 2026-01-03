<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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
            mimeType: $this->guessMimeType($response->json('filename')),
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

    /**
     * Guess the MIME type from a filename.
     */
    protected function guessMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'json' => 'application/json',
            'jsonl' => 'application/jsonl',
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            'html', 'htm' => 'text/html',
            'js' => 'application/javascript',
            'py' => 'text/x-python',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'mp4' => 'video/mp4',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }
}
