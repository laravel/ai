<?php

namespace Laravel\Ai\Gateway\OpenAi;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Concerns\EncodesResourceIds;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class OpenAiFileGateway implements FileGateway
{
    use Concerns\CreatesOpenAiClient;
    use EncodesResourceIds;
    use HandlesFailoverErrors;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("files/{$this->resourceId($fileId)}")
        );

        return (new FileResponse(
            id: $response->json('id'),
        ))->withRawResponse($response);
    }

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse {
        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, $this->providerOptionsKey());

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->withPreparedStorableFile($file, fn ($content, $mime, $name) => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', array_merge(
                    ['purpose' => $this->defaultPurpose()],
                    $providerOptions,
                )))
        );

        return (new StoredFileResponse($response->json('id')))->withRawResponse($response);
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("files/{$this->resourceId($fileId)}")
        );
    }

    /**
     * Get the default purpose to use when a file does not specify one.
     */
    protected function defaultPurpose(): string
    {
        return 'user_data';
    }

    /**
     * Get the provider key used to resolve file upload options.
     */
    protected function providerOptionsKey(): Lab
    {
        return Lab::OpenAI;
    }
}
