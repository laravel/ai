<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Enums\StoreDocumentStatus;

class StoreDocument implements Arrayable, HasProviderId, JsonSerializable
{
    /**
     * Create a new store document instance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $fileId = null,
        public readonly ?StoreDocumentStatus $status = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Get the provider ID of the document within the vector store.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the provider ID of the file the document was created from, if the provider returns it.
     */
    public function fileId(): ?string
    {
        return $this->fileId;
    }

    /**
     * Determine if the provider has finished processing the document.
     */
    public function completed(): bool
    {
        return $this->status === StoreDocumentStatus::Completed;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'file_id' => $this->fileId,
            'status' => $this->status?->value,
            'metadata' => $this->metadata,
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
