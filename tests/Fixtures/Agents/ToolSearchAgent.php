<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\SecretCodeGenerator;

class ToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are an assistant with access to tools. Some tools are not loaded '
            .'upfront and must be discovered using your tool search capability before '
            .'they can be called. Always use the appropriate tool to answer; never guess. '
            .'When asked for the secret authorization code, find and call the tool that returns it.';
    }

    public function tools(Lab|string $provider): iterable
    {
        return [
            new FixedNumberGenerator,
            new ToolSearch(tools: [new SecretCodeGenerator]),
        ];
    }
}
