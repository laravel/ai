<?php

namespace Tests\Feature;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Tools\AgentTool;
use Tests\Feature\Agents\DelegatingAgent;
use Tests\Feature\Agents\SubAgent;
use Tests\Feature\Tools\RandomNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class SubAgentTest extends TestCase
{
    public function test_agent_returned_from_tools_is_wrapped_as_agent_tool(): void
    {
        DelegatingAgent::fake();
        SubAgent::fake(['Research result']);

        (new DelegatingAgent)->prompt('Delegate research about Laravel');

        DelegatingAgent::assertPrompted('Delegate research about Laravel');
    }

    public function test_sub_agent_can_be_faked_independently(): void
    {
        SubAgent::fake(['Sub-agent research result']);

        $response = (new SubAgent)->prompt('Research topic');

        $this->assertEquals('Sub-agent research result', $response->text);
    }

    public function test_anonymous_agent_can_be_used_as_sub_agent(): void
    {
        $subAgent = agent('You are a research assistant.');

        $tool = new AgentTool($subAgent);

        $this->assertEquals('AnonymousAgent', $tool->name());
        $this->assertEquals('You are a research assistant.', $tool->description());
    }

    public function test_agent_tool_uses_name_and_description_from_agent(): void
    {
        $tool = new AgentTool(new SubAgent);

        $this->assertEquals('research_agent', $tool->name());
        $this->assertEquals('Research a topic in depth and return a summary.', $tool->description());
    }

    public function test_agent_tool_exposes_underlying_agent(): void
    {
        $agent = new SubAgent;
        $tool = new AgentTool($agent);

        $this->assertSame($agent, $tool->agent());
    }

    public function test_resolve_tools_wraps_agents_and_preserves_regular_tools(): void
    {
        $delegating = new DelegatingAgent;
        $tools = iterator_to_array($delegating->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(RandomNumberGenerator::class, $tools[0]);

        $resolved = array_map(
            fn ($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
            $tools
        );

        $this->assertInstanceOf(RandomNumberGenerator::class, $resolved[0]);
        $this->assertInstanceOf(AgentTool::class, $resolved[1]);
        $this->assertInstanceOf(SubAgent::class, $resolved[1]->agent());
    }
}
