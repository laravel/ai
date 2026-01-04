<?php

namespace Laravel\Ai\Providers\Tools;

class WebFetch extends ProviderTool
{
    public function __construct(
        public ?int $maxSearches = null,
        public array $allowedDomains = [],
    ) {}

    /**
     * Set the maximum number of searches.
     */
    public function max(int $max): self
    {
        $this->maxSearches = $max;

        return $this;
    }

    /**
     * Set the allowed domains.
     */
    public function allow(array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }
}
