<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

class ToolChoiceAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private ToolChoice|string|array|null $toolChoice = null,
    ) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function toolChoice(): ToolChoice|string|array|null
    {
        return $this->toolChoice;
    }

    public function tools(Lab|string $provider): iterable
    {
        return [
            new RandomNumberGenerator,
            new NamedTool('custom_named_tool'),
        ];
    }
}
