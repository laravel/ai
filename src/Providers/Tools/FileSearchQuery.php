<?php

namespace Laravel\Ai\Providers\Tools;

use Illuminate\Support\Collection;

class FileSearchQuery
{
    /**
     * The defined filters.
     *
     * @var array<int, array{type: string, key: string, value: mixed}>
     */
    protected array $filters = [];

    /**
     * Add a "where" filter to the file search.
     */
    public function where(string $key, mixed $value): self
    {
        $this->filters[] = [
            'type' => 'eq',
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a "where not" filter to the file search.
     */
    public function whereNot(string $key, mixed $value): self
    {
        $this->filters[] = [
            'type' => 'ne',
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a "where in" filter to the file search.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $values
     */
    public function whereIn(string $key, Collection|array $values): self
    {
        $this->filters[] = [
            'type' => 'in',
            'key' => $key,
            'value' => (new Collection($values))->values()->all(),
        ];

        return $this;
    }

    /**
     * Add a "where not in" filter to the file search.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $values
     */
    public function whereNotIn(string $key, Collection|array $values): self
    {
        $this->filters[] = [
            'type' => 'nin',
            'key' => $key,
            'value' => (new Collection($values))->values()->all(),
        ];

        return $this;
    }

    /**
     * Get the filters as an array.
     *
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    public function toArray(): array
    {
        return $this->filters;
    }
}
