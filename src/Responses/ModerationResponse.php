<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ModerationCategory;
use Traversable;

class ModerationResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new moderation response instance.
     *
     * @param  array<int, ModerationCategory>  $categories
     */
    public function __construct(
        public readonly bool $flagged,
        public readonly array $categories,
        public readonly Meta $meta,
    ) {}

    /**
     * Get a specific category by name.
     */
    public function category(string $name): ?ModerationCategory
    {
        return (new Collection($this->categories))->first(
            fn (ModerationCategory $category) => $category->name === $name
        );
    }

    /**
     * Get only the flagged categories.
     */
    public function flagged(): Collection
    {
        return (new Collection($this->categories))->filter->flagged->values();
    }

    /**
     * Get the number of categories in the response.
     */
    public function count(): int
    {
        return count($this->categories);
    }

    /**
     * Get the categories as a collection.
     */
    public function collect(): Collection
    {
        return new Collection($this->categories);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'flagged' => $this->flagged,
            'categories' => $this->categories,
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
     * Get an iterator for the categories.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->categories as $category) {
            yield $category;
        }
    }
}
