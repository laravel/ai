<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\HasName;

abstract class File implements HasName
{
    public ?string $name = null;

    public ?string $mime = null;

    /**
     * Reconstruct a file instance from its array representation.
     */
    public static function fromArray(array $data): ?File
    {
        $file = match ($data['type'] ?? null) {
            'base64-image' => new Base64Image($data['base64'] ?? '', $data['mime'] ?? null),
            'local-image' => new LocalImage($data['path'] ?? '', $data['mime'] ?? null),
            'stored-image' => new StoredImage($data['path'] ?? '', $data['disk'] ?? null),
            'remote-image' => new RemoteImage($data['url'] ?? '', $data['mime'] ?? null),
            'provider-image' => new ProviderImage($data['id'] ?? ''),
            'base64-document' => new Base64Document($data['base64'] ?? '', $data['mime'] ?? null),
            'local-document' => new LocalDocument($data['path'] ?? '', $data['mime'] ?? null),
            'stored-document' => new StoredDocument($data['path'] ?? '', $data['disk'] ?? null),
            'remote-document' => new RemoteDocument($data['url'] ?? '', $data['mime'] ?? null),
            'provider-document' => new ProviderDocument($data['id'] ?? ''),
            'base64-audio' => new Base64Audio($data['base64'] ?? '', $data['mime'] ?? null),
            'local-audio' => new LocalAudio($data['path'] ?? '', $data['mime'] ?? null),
            'stored-audio' => new StoredAudio($data['path'] ?? '', $data['disk'] ?? null),
            'remote-audio' => new RemoteAudio($data['url'] ?? '', $data['mime'] ?? null),
            default => null,
        };

        if ($file !== null && isset($data['name'])) {
            $file->as($data['name']);
        }

        return $file;
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Set the displayable name of the file.
     */
    public function as(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the file's MIME type.
     */
    public function withMimeType(string $mimeType): static
    {
        $this->mime = $mimeType;

        return $this;
    }
}
