<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\Data\StoreFileCounts;

class Store
{
    public function __construct(
        protected StoreProvider $provider,
        public readonly string $id,
        public readonly ?string $name,
        public readonly StoreFileCounts $fileCounts,
        public readonly bool $ready,
    ) {}

    /**
     * Refresh the store from the provider.
     */
    public function refresh(): self
    {
        return $this->provider->getStore($this->id);
    }

    /**
     * Delete the store from the provider.
     */
    public function delete(): bool
    {
        return $this->provider->deleteStore($this->id);
    }
}
