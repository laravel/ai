<?php

namespace Laravel\Ai\Providers\Tools;

use Illuminate\Support\Collection;
use Laravel\Ai\Store;

class FileSearch extends ProviderTool
{
    /**
     * Create a new file search tool instance.
     */
    public function __construct(
        public array $stores,
        public array $where = [],
    ) {}

    /**
     * Filter the search results by metadata.
     */
    public function where(array $where): self
    {
        $this->where = $where;

        return $this;
    }

    /**
     * Get the string store IDs assigned to the tool.
     */
    public function ids(): array
    {
        return (new Collection($this->stores))
            ->map(function ($store) {
                return $store instanceof Store
                    ? $store->id
                    : $store;
            })->all();
    }
}
