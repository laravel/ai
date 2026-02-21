<?php

namespace Laravel\Ai\Providers\Tools;

class WebSearch extends ProviderTool
{
    public ?string $city = null;

    public ?string $region = null;

    public ?string $country = null;

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

    /**
     * Set the user's location to refine search results based on the given location.
     */
    public function location(?string $city = null, ?string $region = null, ?string $country = null): self
    {
        $this->city = $city;
        $this->region = $region;
        $this->country = $country;

        return $this;
    }

    /**
     * Determine if the web search uses the user's location.
     */
    public function hasLocation(): bool
    {
        return isset($this->city) ||
            isset($this->region) ||
            isset($this->country);
    }
}
