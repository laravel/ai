<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Support\Collection;

class Meta
{
    public Collection $citations;

    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?Collection $citations = null,
    ) {
        $this->citations = $citations ?? new Collection;
    }
}
