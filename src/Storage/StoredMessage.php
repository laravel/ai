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
        public ?CarbonInterface $approvalRequestedAt = null,
        public array $attachments = [],
        public ?CarbonInterface $completedAt = null,
        public ?CarbonInterface $failedAt = null,
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
            approvalRequestedAt: blank($record['approval_requested_at'] ?? null) ? null : Carbon::parse($record['approval_requested_at']),
            attachments: array_values(static::decoded($record['attachments'] ?? null)),
            completedAt: blank($record['completed_at'] ?? null) ? null : Carbon::parse($record['completed_at']),
            failedAt: blank($record['failed_at'] ?? null) ? null : Carbon::parse($record['failed_at']),
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
            'approval_requested_at' => $this->approvalRequestedAt?->toJSON(),
            'attachments' => $this->attachments,
            'completed_at' => $this->completedAt?->toJSON(),
            'failed_at' => $this->failedAt?->toJSON(),
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
     * The provider-hosted tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function providerToolCalls(): array
    {
        return Arr::collapse(array_column($this->steps, 'provider_tool_calls'));
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function toolResults(): array
    {
        return array_values(array_map(
            fn (array $toolCall): array => Arr::only($toolCall, ['id', 'name', 'arguments', 'result', 'result_id', 'denied', 'failed']),
            array_filter($this->toolCalls(), fn (array $toolCall): bool => array_key_exists('result', $toolCall)),
        ));
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
