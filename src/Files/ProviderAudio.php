<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider;

class ProviderAudio extends Audio implements Arrayable, HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProvider;

    public function __construct(public string $id, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * ProviderAudio references cannot be read directly.
     *
     * @throws InvalidArgumentException
     */
    public function content(): string
    {
        throw new InvalidArgumentException(
            'ProviderAudio cannot be read directly. It is a reference to a file stored on an external provider.'
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'provider-audio',
            'id' => $this->id,
            'name' => $this->name(),
            'mime' => $this->mimeType(),
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
