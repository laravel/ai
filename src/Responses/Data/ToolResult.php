<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

class ToolResult implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, File>  $attachments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public mixed $result,
        public ?string $resultId = null,
        public bool $denied = false,
        public bool $failed = false,
        public array $attachments = [],
    ) {}

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            arguments: $data['arguments'] ?? [],
            result: $data['result'],
            resultId: $data['result_id'] ?? null,
            denied: $data['denied'] ?? false,
            failed: $data['failed'] ?? false,
            attachments: collect($data['attachments'] ?? [])->map(File::fromArray(...))->filter()->values()->all(),
        );
    }

    /**
     * Determine if the tool call ran and produced its own result.
     */
    public function successful(): bool
    {
        return ! $this->denied && ! $this->failed;
    }

    /**
     * Get the message explaining why the tool call did not succeed.
     */
    public function error(): ?string
    {
        return $this->successful() || ! is_string($this->result) ? null : $this->result;
    }

    /**
     * Get the result as a string suitable for sending back to a provider, noting each attached file it cannot receive.
     */
    public function text(): string
    {
        return collect($this->attachments)
            ->map($this->attachmentNote(...))
            ->prepend($this->resultText())
            ->implode("\n");
    }

    /**
     * Get the note sent in place of the given attached file to a provider that cannot receive it.
     */
    public function attachmentNote(File $attachment): string
    {
        return Str::squish('[The '.($attachment instanceof Image ? 'image' : 'document').' '.$attachment->name().' was left out because it could not be sent to this provider.]');
    }

    /**
     * Get the result as a string, for a provider that receives the attached files alongside it.
     */
    public function resultText(): string
    {
        return match (true) {
            is_string($this->result) => $this->result,
            is_array($this->result) => (string) json_encode($this->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => strval($this->result),
        };
    }

    /**
     * Get the instance as an array, only including the denied, failed and attachments keys when they apply.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_id' => $this->resultId,
            ...($this->denied ? ['denied' => true] : []),
            ...($this->failed ? ['failed' => true] : []),
            ...($this->attachments === [] ? [] : ['attachments' => collect($this->attachments)->toArray()]),
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
