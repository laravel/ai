<?php

namespace Laravel\Ai\Providers\Tools;

use Illuminate\Support\Collection;
use Laravel\Ai\Store;

class FileSearch extends ProviderTool
{
    public function __construct(
        public array $stores,
    ) {}

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
