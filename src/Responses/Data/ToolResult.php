<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

class ToolResult implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, Image|Document>  $attachments  The files the tool returned for the model to see.
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
            attachments: array_values(array_filter(array_map(
                fn (mixed $attachment) => is_array($attachment) ? File::fromArray($attachment) : null,
                $data['attachments'] ?? [],
            ), fn (?File $file) => $file instanceof Image || $file instanceof Document)),
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
     * Get the result as a string suitable for sending back to a provider, noting each attached file it isn't sent.
     */
    public function text(): string
    {
        return implode("\n", array_filter([
            $this->resultText(),
            ...array_map($this->attachmentNote(...), $this->attachments),
        ], fn (string $text) => $text !== ''));
    }

    /**
     * Get the note sent in place of an attached file that a provider can't receive.
     */
    public function attachmentNote(Image|Document $attachment): string
    {
        $kind = $attachment instanceof Image ? 'image' : 'document';
        $name = filled($attachment->name()) ? " [{$attachment->name()}]" : '';

        return "(The tool returned the {$kind}{$name}, but it could not be sent to this model.)";
    }

    /**
     * Get the result as a string, without notes about its attached files.
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
            ...($this->attachments === [] ? [] : ['attachments' => array_values(array_filter(array_map(
                fn (File $file) => $file instanceof Arrayable ? $file->toArray() : null,
                $this->attachments,
            )))]),
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
