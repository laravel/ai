<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Enums\PromptCacheTarget;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class PromptCacheAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(
        public mixed $cache = [PromptCacheTarget::System, PromptCacheTarget::Tools],
        public bool $withTools = true,
        public array $otherOptions = [],
    ) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return $this->withTools ? [new FixedNumberGenerator] : [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['prompt_cache' => $this->cache, ...$this->otherOptions];
    }
}
