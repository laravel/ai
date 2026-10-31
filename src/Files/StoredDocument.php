<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasStoredContent;

class StoredDocument extends Document implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasStoredContent;

    public function __construct(public string $path, public ?string $disk = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Document file path cannot be empty.');
        }
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'stored-document',
            'name' => $this->name(),
            'path' => $this->path,
            'disk' => $this->disk ?? config('filesystems.default'),
        ];
    }
}
