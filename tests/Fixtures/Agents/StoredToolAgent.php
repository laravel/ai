<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class StoredToolAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(private array $options = []) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }

    public function maxSteps(): int
    {
        return 5;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['store' => true, ...$this->options];
    }
}
