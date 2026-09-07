<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use RuntimeException;

class LocalDocument extends Document implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;

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
     * Get the displayable name of the file.
     */
    #[\Override]
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    #[\Override]
    public function mimeType(): ?string
    {
        return $this->mime ?? (new Filesystem)->mimeType($this->path);
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
