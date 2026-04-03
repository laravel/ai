<?php

namespace Tests\Unit\Tools;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\AgentTool;
use PHPUnit\Framework\TestCase;

class AgentToolTest extends TestCase
{
    public function test_name_uses_agent_name_method_when_available(): void
    {
        $agent = new class implements Agent
        {
            use Promptable;

            public function name(): string
            {
                return 'custom_agent';
            }

            public function instructions(): string
            {
                return 'Test instructions.';
            }
        };

        $tool = new AgentTool($agent);

        $this->assertEquals('custom_agent', $tool->name());
    }

    public function test_name_falls_back_to_class_basename(): void
    {
        $agent = $this->createMock(Agent::class);

        $tool = new AgentTool($agent);

        $this->assertNotEmpty($tool->name());
    }

    public function test_description_uses_agent_description_method_when_available(): void
    {
        $agent = new class implements Agent
        {
            use Promptable;

            public function description(): string
            {
                return 'A specialized research agent.';
            }

            public function instructions(): string
            {
                return 'You are a research agent with very long instructions.';
            }
        };

        $tool = new AgentTool($agent);

        $this->assertEquals('A specialized research agent.', $tool->description());
    }

    public function test_description_falls_back_to_instructions(): void
    {
        $agent = new class implements Agent
        {
            use Promptable;

            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        $tool = new AgentTool($agent);

        $this->assertEquals('You are a helpful assistant.', $tool->description());
    }

    public function test_schema_returns_task_string_parameter(): void
    {
        $agent = $this->createMock(Agent::class);

        $tool = new AgentTool($agent);
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('task', $schema);
    }

    public function test_agent_returns_underlying_agent(): void
    {
        $agent = $this->createMock(Agent::class);

        $tool = new AgentTool($agent);

        $this->assertSame($agent, $tool->agent());
    }
}
