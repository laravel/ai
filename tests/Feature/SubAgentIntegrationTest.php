<?php

namespace Tests\Feature;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasSubAgents;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SubAgentTool;
use Tests\Feature\Agents\DeterministicSubAgent;
use Tests\Feature\Agents\PrimaryWithSubAgentsAgent;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class SubAgentIntegrationTest extends TestCase
{
    public function test_subagent_tool_executes_with_a_task_string(): void
    {
        DeterministicSubAgent::fake(['subagent: summarize this']);

        $tool = new SubAgentTool(new DeterministicSubAgent);

        $this->assertSame('deterministic_subagent', $tool->name());
        $this->assertSame('subagent: summarize this', (string) $tool->handle(new Request([
            'task' => 'summarize this',
        ])));

        DeterministicSubAgent::assertPrompted('summarize this');
    }

    public function test_primary_agent_tools_include_mapped_subagents(): void
    {
        $harness = new class
        {
            use GeneratesText;

            public function toolsFor(Agent $agent): array
            {
                return $this->gatherToolsFor($agent);
            }
        };

        $tools = $harness->toolsFor(new PrimaryWithSubAgentsAgent);

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(FixedNumberGenerator::class, $tools[0]);
        $this->assertInstanceOf(SubAgentTool::class, $tools[1]);
    }

    public function test_empty_agents_array_does_not_add_subagent_tools(): void
    {
        $harness = new class
        {
            use GeneratesText;

            public function toolsFor(Agent $agent): array
            {
                return $this->gatherToolsFor($agent);
            }
        };

        $tools = $harness->toolsFor(new class implements Agent, HasSubAgents
        {
            use \Laravel\Ai\Promptable;

            public function instructions(): string
            {
                return 'No tools';
            }

            public function subAgents(): array
            {
                return [];
            }
        });

        $this->assertSame([], $tools);
    }

    public function test_anonymous_agent_helper_accepts_subagents(): void
    {
        $agent = agent(agents: [new DeterministicSubAgent]);

        $this->assertInstanceOf(HasSubAgents::class, $agent);
        /** @var HasSubAgents $agent */
        $this->assertCount(1, $agent->subAgents());
        $this->assertInstanceOf(DeterministicSubAgent::class, $agent->subAgents()[0]);
    }

    public function test_structured_anonymous_agent_helper_accepts_subagents(): void
    {
        $agent = agent(
            schema: fn ($schema) => ['value' => $schema->string()->required()],
            agents: [new DeterministicSubAgent],
        );

        $this->assertInstanceOf(HasSubAgents::class, $agent);
        /** @var HasSubAgents $agent */
        $this->assertCount(1, $agent->subAgents());
        $this->assertInstanceOf(DeterministicSubAgent::class, $agent->subAgents()[0]);
    }
}
