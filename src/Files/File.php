<?php

namespace Laravel\Ai\Files;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\HasName;
use Laravel\Ai\Contracts\Files\HasPurpose;

abstract class File implements HasName, HasPurpose
{
    public ?string $name = null;

    public ?string $mime = null;

    public ?string $purpose = null;

    /**
     * Reconstruct a file instance from its array representation.
     */
    public static function fromArray(array $data): ?File
    {
        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            return null;
        }

        $file = match ($type) {
            'base64-image' => new Base64Image(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-image' => new LocalImage(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-image' => new StoredImage(self::value($data, 'path', $type), $data['disk'] ?? null),
            'remote-image' => new RemoteImage(self::value($data, 'url', $type), $data['mime'] ?? null),
            'provider-image' => new ProviderImage(self::value($data, 'id', $type)),
            'base64-document' => new Base64Document(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-document' => new LocalDocument(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-document' => new StoredDocument(self::value($data, 'path', $type), $data['disk'] ?? null),
            'remote-document' => new RemoteDocument(self::value($data, 'url', $type), $data['mime'] ?? null),
            'provider-document' => new ProviderDocument(self::value($data, 'id', $type)),
            'base64-audio' => new Base64Audio(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-audio' => new LocalAudio(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-audio' => new StoredAudio(self::value($data, 'path', $type), $data['disk'] ?? null),
            'remote-audio' => new RemoteAudio(self::value($data, 'url', $type), $data['mime'] ?? null),
            default => null,
        };

        if ($file !== null && array_key_exists('name', $data)) {
            $file->as($data['name']);
        }

        return $file;
    }

    protected static function value(array $data, string $key, string $type): string
    {
        if (! isset($data[$key])) {
            throw new InvalidArgumentException("Cannot reconstruct [{$type}] attachment because [{$key}] is missing or invalid.");
        }

        return $data[$key];
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
     * Get the purpose of the file.
     */
    public function purpose(): ?string
    {
        return $this->purpose;
    }

    /**
     * Set the file's purpose to a custom value.
     */
    public function for(string $purpose): static
    {

        if (! in_array($purpose, ['assistants', 'batch', 'fine-tune', 'vision', 'user_data', 'evals'])) {
            throw new InvalidArgumentException("Invalid file purpose [{$purpose}].");
        }

        $this->purpose = $purpose;

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
