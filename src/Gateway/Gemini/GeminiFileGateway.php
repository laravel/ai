<?php

namespace Laravel\Ai\Gateway\Gemini;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\EncodesResourceIds;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Laravel\Ai\Gateway\Gemini\Concerns\ResolvesFileUris;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class GeminiFileGateway implements FileGateway
{
    use CreatesClient;
    use EncodesResourceIds;
    use HandlesFailoverErrors;
    use PreparesStorableFiles;
    use ResolvesFileUris;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get($this->baseUrl($provider)."/{$fileId}")->throw(),
        );

        return (new FileResponse(
            id: $response->json('name'),
            mimeType: $response->json('mimeType'),
        ))->withRawResponse($response);
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

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::Gemini);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)->attach(
            'file', $content, $name, ['Content-Type' => $mime]
        )->post("{$uploadUrl}/files", array_replace_recursive([
            'file' => ['display_name' => $name],
        ], $providerOptions))->throw());

        return (new StoredFileResponse($response->json('file.name')))->withRawResponse($response);
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $fileId = $this->normalizeFileId($fileId);

        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete($this->baseUrl($provider)."/{$fileId}")->throw(),
        );
    }

    /**
     * Normalize the file ID, name, or URI to the Files API resource name.
     */
    protected function normalizeFileId(string $fileId): string
    {
        return 'files/'.$this->resourceId($this->fileId($fileId));
    }

    protected function client(Provider $provider): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            array_filter(['x-goog-api-key' => $provider->providerCredentials()['key']]),
            $provider->additionalConfiguration()['headers'] ?? [],
            timeout: null,
            throw: false,
        );
    }

    /**
     * Get the base URL for the Gemini API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');
    }
}
