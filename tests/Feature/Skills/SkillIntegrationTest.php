<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Skills\ActivateSkillTool;
use Laravel\Ai\Skills\Exceptions\SkillNotFoundException;
use Laravel\Ai\Skills\ReadSkillResourceTool;
use Laravel\Ai\Skills\SkillLoader;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class SkillIntegrationTest extends TestCase
{
    public function test_agent_can_use_skills_in_instructions(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $agent = new ExampleAgentWithSkills($registry);
        $instructions = $agent->instructions();

        $this->assertStringContainsString('<available_skills>', $instructions);
        $this->assertStringContainsString('customer-support', $instructions);
        $this->assertStringContainsString('order-fulfillment', $instructions);
    }

    public function test_agent_without_skills_has_clean_instructions(): void
    {
        $registry = new SkillRegistry;

        $agent = new ExampleAgentWithSkills($registry);
        $instructions = $agent->instructions();

        $this->assertStringNotContainsString('<available_skills>', $instructions);
        $this->assertStringContainsString('You are a helpful assistant', $instructions);
    }

    public function test_activate_skill_tool_returns_full_content(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'customer-support']));

        $this->assertStringContainsString('Customer Support Skill', $result);
        $this->assertStringContainsString('Priority levels', $result);
    }

    public function test_activate_skill_tool_includes_available_resources(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'customer-support']));

        $this->assertStringContainsString('<available_resources>', $result);
        $this->assertStringContainsString('scripts/triage.sh', $result);
        $this->assertStringContainsString('references/TEMPLATES.md', $result);
    }

    public function test_activate_skill_tool_omits_resources_when_none_exist(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'order-fulfillment']));

        $this->assertStringNotContainsString('<available_resources>', $result);
    }

    public function test_read_skill_resource_returns_file_content(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'scripts/triage.sh']));

        $this->assertStringContainsString('Running ticket triage', $result);
    }

    public function test_read_skill_resource_returns_reference_content(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'references/TEMPLATES.md']));

        $this->assertStringContainsString('Response Templates', $result);
        $this->assertStringContainsString('Thank you for contacting support', $result);
    }

    public function test_read_skill_resource_returns_not_found_for_missing_file(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'scripts/nonexistent.sh']));

        $this->assertSame('Resource not found.', $result);
    }

    public function test_read_skill_resource_prevents_path_traversal(): void
    {
        $loader = new SkillLoader;
        $registry = new SkillRegistry;

        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');
        foreach ($skills as $skill) {
            $registry->register($skill);
        }

        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => '../order-fulfillment/SKILL.md']));

        $this->assertSame('Resource not found.', $result);
    }

    public function test_activate_skill_tool_throws_for_unknown_skill(): void
    {
        $registry = new SkillRegistry;
        $tool = new ActivateSkillTool($registry);

        $this->expectException(SkillNotFoundException::class);

        $tool->handle(new Request(['name' => 'nonexistent']));
    }

    public function test_agent_exposes_skill_tools(): void
    {
        $registry = new SkillRegistry;
        $agent = new ExampleAgentWithSkills($registry);

        $tools = iterator_to_array($agent->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(ActivateSkillTool::class, $tools[0]);
        $this->assertInstanceOf(ReadSkillResourceTool::class, $tools[1]);
    }
}

class ExampleAgentWithSkills implements Agent, HasTools
{
    use Promptable;

    public function __construct(protected SkillRegistry $skills) {}

    public function instructions(): string
    {
        $instructions = 'You are a helpful assistant.';

        if ($this->skills->count() > 0) {
            $instructions .= "\n\n".$this->skills->toPrompt();
        }

        return $instructions;
    }

    public function tools(): iterable
    {
        return [
            new ActivateSkillTool($this->skills),
            new ReadSkillResourceTool($this->skills),
        ];
    }
}
