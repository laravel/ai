<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class Meta implements Arrayable, JsonSerializable
{
    public Collection $citations;

    public Collection $searchQueries;

    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?Collection $citations = null,
        ?Collection $searchQueries = null,
    ) {
        $this->citations = $citations ?? new Collection;
        $this->searchQueries = $searchQueries ?? new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'citations' => $this->citations
                ? $this->citations->all()
                : [],
            'search_queries' => $this->searchQueries
                ? $this->searchQueries->all()
                : [],
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
