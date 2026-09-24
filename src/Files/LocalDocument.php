<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasLocalContent;

class LocalDocument extends Document implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasLocalContent;

    public function __construct(public string $path, ?string $mimeType = null, protected ?UploadedFile $upload = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Document file path cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Create a document from an uploaded file, holding the upload so its temporary file is not discarded.
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        return (new self(
            $file->getPathname(),
            $file->getClientMimeType(),
            $file,
        ))->as($file->getClientOriginalName());
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-document',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);

        unset($data['upload']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
