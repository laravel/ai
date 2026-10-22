<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\HasStream;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasStoredContent;

class StoredDocument extends Document implements Arrayable, JsonSerializable, HasStream, StorableFile
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
     * Open the document on its configured disk without buffering it.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        $stream = Storage::disk($this->disk)->readStream($this->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('File ['.$this->path.'] does not exist on disk ['.$this->disk.'].');
        }

        return $stream;
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
