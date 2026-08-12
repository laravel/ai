<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class PromptCacheAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(
        protected mixed $cache = [],
        protected bool $withTools = true,
        protected array $options = [],
    ) {
        //
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return $this->withTools ? [new FixedNumberGenerator] : [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [...$this->options, 'prompt_cache' => $this->cache];
    }
}
