<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class S3Document extends Document implements Arrayable, JsonSerializable
{
    /**
     * Create a new S3Document instance.
     */
    public function __construct(
        public string $url,
        public ?string $bucketOwner = null,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return $this->name ?? basename(is_string($path) ? $path : '');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function content(): string
    {
        throw new InvalidArgumentException(
            'S3Document cannot be read directly. It is only supported by providers that accept S3 location references. Use StoredDocument or RemoteDocument instead if you need to send the file contents inline.'
        );
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 's3-document',
            'name' => $this->name(),
            'url' => $this->url,
            'bucket_owner' => $this->bucketOwner,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
