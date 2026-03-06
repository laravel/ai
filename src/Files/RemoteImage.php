<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasRemoteContent;

class RemoteImage extends Image implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider, HasRemoteContent;

    public ?string $mime = null;

    public function __construct(public string $url, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'remote-image',
            'name' => $this->name,
            'url' => $this->url,
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
