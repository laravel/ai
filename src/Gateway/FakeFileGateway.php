<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;
use RuntimeException;

class FakeFileGateway implements FileGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayOperations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        return $this->nextGetResponse($fileId);
    }

    /**
     * Get the next response for a get request.
     */
    protected function nextGetResponse(string $fileId): FileResponse
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $fileId);

        return tap($this->marshalGetResponse(
            $response, $fileId
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a FileResponse instance.
     */
    protected function marshalGetResponse(mixed $response, string $fileId): FileResponse
    {
        if ($response instanceof Closure) {
            $response = $response($fileId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted file retrieval without a fake response.');
            }

            return new FileResponse($fileId, 'fake-content', 'text/plain');
        }

        if (is_string($response)) {
            return new FileResponse($fileId, $response, 'text/plain');
        }

        return $response;
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
        return new StoredFileResponse('file_'.Str::random(24));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        //
    }

    /**
     * Indicate that an exception should be thrown if any file operation is not faked.
     */
    public function preventStrayOperations(bool $prevent = true): self
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
