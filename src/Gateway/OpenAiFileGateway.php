<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class OpenAiFileGateway implements FileGateway
{
    use Concerns\HandlesRateLimiting;
    use Concerns\PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("files/{$fileId}")
                ->throw()
        );

        return new FileResponse(
            id: $response->json('id'),
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
                ->post('files', [
                    'purpose' => $this->filePurpose(),
                ])
                ->throw()
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
            fn () => $this->client($provider)
                ->delete("files/{$fileId}")
                ->throw()
        );
    }

    /**
     * Get the file upload purpose.
     */
    protected function filePurpose(): string
    {
        return 'user_data';
    }

    /**
     * Get a configured HTTP client for the given provider.
     */
    protected function client(FileProvider $provider): PendingRequest
    {
        return Http::withToken($provider->providerCredentials()['key'])
            ->baseUrl('https://api.openai.com/v1');
    }
}
