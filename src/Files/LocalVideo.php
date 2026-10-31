<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasLocalContent;

class LocalVideo extends Video implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasLocalContent;

    public function __construct(public string $path, ?string $mimeType = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Video file path cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-video',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}
