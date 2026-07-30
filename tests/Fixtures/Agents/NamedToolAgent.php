<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\NamedTool;

class NamedToolAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(public readonly string $toolName = 'custom_named_tool') {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(Lab|string $provider): iterable
    {
        return [
            new NamedTool($this->toolName),
        ];
    }
}
