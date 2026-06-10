<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\ToolSearch;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

#[Provider('anthropic')]
#[Model('claude-opus-4-8')]
#[ToolSearch]
class AnthropicToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [new DeferredTool, new NonStrictTool];
    }
}
