<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Voice;
use Traversable;

class VoicesResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new voices response instance.
     *
     * @param  array<int, Voice>  $voices
     */
    public function __construct(
        public readonly array $voices,
        public readonly Meta $meta,
    ) {}

    /**
     * Get the first voice in the response.
     */
    public function first(): ?Voice
    {
        return $this->voices[0] ?? null;
    }

    /**
     * Find a voice by its ID.
     */
    public function find(string $id): ?Voice
    {
        return $this->collect()->first(fn (Voice $voice): bool => $voice->id === $id);
    }

    /**
     * Get the number of voices in the response.
     */
    public function count(): int
    {
        return count($this->voices);
    }

    /**
     * Get the voices as a collection.
     *
     * @return Collection<int, Voice>
     */
    public function collect(): Collection
    {
        return new Collection($this->voices);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'voices' => $this->voices,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get an iterator for the voices.
     *
     * @return Traversable<int, Voice>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->voices as $voice) {
            yield $voice;
        }
    }
}
