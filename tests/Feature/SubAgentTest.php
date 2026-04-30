<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\SubAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

test('agent returned from tools is wrapped as agent tool', function () {
    DelegatingAgent::fake();
    SubAgent::fake(['Research result']);

    (new DelegatingAgent)->prompt('Delegate research about Laravel');

    DelegatingAgent::assertPrompted('Delegate research about Laravel');
});

test('sub agent can be faked independently', function () {
    SubAgent::fake(['Sub-agent research result']);

    $response = (new SubAgent)->prompt('Research topic');

    expect($response->text)->toBe('Sub-agent research result');
});

test('anonymous agent can be used as sub agent', function () {
    $subAgent = agent('You are a research assistant.');

    $tool = new AgentTool($subAgent);

    expect($tool->name())->toBe('AnonymousAgent')
        ->and($tool->description())->toBe('You are a research assistant.');
});

test('agent tool uses name and description from agent', function () {
    $tool = new AgentTool(new SubAgent);

    expect($tool->name())->toBe('research_agent')
        ->and($tool->description())->toBe('Research a topic in depth and return a summary.');
});

test('agent tool exposes underlying agent', function () {
    $agent = new SubAgent;
    $tool = new AgentTool($agent);

    expect($tool->agent())->toBe($agent);
});

test('resolve tools wraps agents and preserves regular tools', function () {
    $delegating = new DelegatingAgent;
    $tools = iterator_to_array($delegating->tools());

    expect($tools)->toHaveCount(2);
    expect($tools[0])->toBeInstanceOf(RandomNumberGenerator::class);

    $resolved = array_map(
        fn ($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
        $tools
    );

    expect($resolved[0])->toBeInstanceOf(RandomNumberGenerator::class)
        ->and($resolved[1])->toBeInstanceOf(AgentTool::class)
        ->and($resolved[1]->agent())->toBeInstanceOf(SubAgent::class);
});
