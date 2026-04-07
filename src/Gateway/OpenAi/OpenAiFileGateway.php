<?php

namespace Laravel\Ai\Gateway\OpenAi;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class OpenAiFileGateway implements FileGateway
{
    use Concerns\CreatesOpenAiClient;
    use HandlesRateLimiting;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("files/{$fileId}")
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
                    'purpose' => 'user_data',
                ])
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
        );
    }
}
