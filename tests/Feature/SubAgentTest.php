<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\ResearchAgent;

test('agent returned from tools is invoked when called by parent agent', function () {
    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent)->prompt('Delegate research about Laravel');

    DelegatingAgent::assertPrompted('Delegate research about Laravel');
    ResearchAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->prompt === 'Research Laravel';
    });

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('research_agent')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research result');
});

test('research agent can be faked independently', function () {
    ResearchAgent::fake(['Research result']);

    $response = (new ResearchAgent)->prompt('Research topic');

    expect($response->text)->toBe('Research result');
});

test('agent tool uses name and description from agent when defined', function () {
    $tool = new AgentTool(new ResearchAgent);

    expect($tool->name())->toBe('research_agent')
        ->and($tool->description())->toBe('Research a topic in depth and return a summary.');
});

test('agent tool falls back to class basename for name', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function description(): string
        {
            return 'A nameless agent.';
        }

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);

    expect($tool->name())->not->toBeEmpty()
        ->and($tool->description())->toBe('A nameless agent.');
});

test('agent tool falls back to a generic description that does not leak instructions', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function name(): string
        {
            return 'silent_agent';
        }

        public function instructions(): string
        {
            return 'You are a silent agent with very long internal instructions that should never be sent to the parent LLM as a tool description.';
        }
    };

    $tool = new AgentTool($agent);

    expect((string) $tool->description())
        ->toStartWith('Delegates a task to the silent_agent sub-agent')
        ->not->toContain('long internal instructions');
});

test('framework wraps an agent in tools automatically when resolving', function () {
    $tools = (new DelegatingAgent)->tools();

    $resolved = array_map(
        fn ($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
        [...$tools],
    );

    expect($resolved[0])->toBeInstanceOf(AgentTool::class)
        ->and($resolved[0]->agent())->toBeInstanceOf(ResearchAgent::class);
});
