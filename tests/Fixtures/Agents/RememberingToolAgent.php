<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class RememberingToolAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(public array $extraProviderOptions = []) {}

    public function instructions(): string
    {
        return 'Call the FixedNumberGenerator tool before answering. Answer with one short sentence.';
    }

    public function tools(Lab|string $provider): iterable
    {
        return [new FixedNumberGenerator];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->extraProviderOptions;
    }
}
