<?php

namespace Laravel\Ai\Providers\Tools;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Store;

class FileSearch extends ProviderTool
{
    /**
     * The file search filters.
     *
     * @var array<int, array{type: string, key: string, value: mixed}>
     */
    public array $filters = [];

    /**
     * Create a new file search tool instance.
     *
     * @param  array|(\Closure(FileSearchQuery): mixed)|null  $where
     */
    public function __construct(
        public array $stores,
        Closure|array|null $where = null,
    ) {
        $this->filters = $this->resolveFilters($where);
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

    /**
     * Resolve the filters from the given value.
     *
     * @param  (\Closure(FileSearchQuery): mixed)|array|null  $where
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    protected function resolveFilters(Closure|array|null $where): array
    {
        if (is_null($where)) {
            return [];
        }

        if (is_array($where)) {
            return (new Collection($where))->map(fn ($value, $key) => [
                'type' => 'eq',
                'key' => $key,
                'value' => $value,
            ])->values()->all();
        }

        $where($query = new FileSearchQuery);

        return $query->toArray();
    }
}
