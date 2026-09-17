<?php

namespace Laravel\Ai\Storage;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * A conversation message as it was persisted, with its JSON columns decoded.
 *
 * `Message` is what the model sees; this is what was stored, and it keeps the
 * identity and timestamps a rendered transcript needs.
 */
class StoredMessage implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>|null  $approvalState
     * @param  list<array<string, mixed>>  $attachments
     */
    public function __construct(
        public string $id,
        public string $role,
        public string $content,
        public ?CarbonInterface $createdAt = null,
        public array $usage = [],
        public array $meta = [],
        public array $steps = [],
        public ?array $approvalState = null,
        public array $attachments = [],
    ) {}

    /**
     * Reconstruct an instance from a stored row, decoding its JSON columns.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        return new self(
            id: (string) ($record['id'] ?? ''),
            role: (string) ($record['role'] ?? ''),
            content: (string) ($record['content'] ?? ''),
            createdAt: blank($record['created_at'] ?? null) ? null : Carbon::parse($record['created_at']),
            usage: static::decoded($record['usage'] ?? null),
            meta: static::decoded($record['meta'] ?? null),
            steps: array_values(static::decoded($record['steps'] ?? null)),
            approvalState: blank($record['approval_state'] ?? null) ? null : static::decoded($record['approval_state']),
            attachments: array_values(static::decoded($record['attachments'] ?? null)),
        );
    }

    /**
     * Get the instance as an array, in the shape it was stored in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'created_at' => $this->createdAt?->toJSON(),
            'usage' => $this->usage,
            'meta' => $this->meta,
            'steps' => $this->steps,
            'approval_state' => $this->approvalState,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function toolCalls(): array
    {
        return Arr::collapse(array_column($this->steps, 'tool_calls'));
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function toolResults(): array
    {
        return Arr::collapse(array_column($this->steps, 'tool_results'));
    }

    /**
     * Decode a JSON column, tolerating null and malformed values.
     *
     * @return array<mixed>
     */
    protected static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?: '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
