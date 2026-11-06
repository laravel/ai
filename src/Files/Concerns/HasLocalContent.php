<?php

namespace Laravel\Ai\Files\Concerns;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\Filesystem;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

trait HasLocalContent
{
    /**
     * Get the raw representation of the file.
     *
     * @throws RuntimeException if the file does not exist at the configured path.
     */
    public function content(): string
    {
        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("File does not exist at path [{$this->path}]");
        }

        return $content;
    }

    /**
     * Open a fresh read stream of the file; the caller closes it.
     *
     * @throws RuntimeException if the file does not exist at the configured path.
     */
    public function stream(): StreamInterface
    {
        return Utils::streamFor(
            @fopen($this->path, 'rb') ?: throw new RuntimeException("File does not exist at path [{$this->path}]")
        );
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime ?? ((new Filesystem)->mimeType($this->path) ?: null);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->content();
    }
}
