<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Concerns\LoadsSkills;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\SupportSkills;
use Laravel\Ai\Promptable;
use Laravel\Ai\Skills\ActivateSkillTool;
use Laravel\Ai\Skills\Exceptions\SkillNotFoundException;
use Laravel\Ai\Skills\ReadSkillResourceTool;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillLoader;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class SkillIntegrationTest extends TestCase
{
    public function test_agent_can_use_skills_in_instructions(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');

        $agent = new ManualSkillsAgent($registry);

        $this->assertStringContainsString('<available_skills>', $agent->instructions());
        $this->assertStringContainsString('customer-support', $agent->instructions());
        $this->assertStringContainsString('order-fulfillment', $agent->instructions());
    }

    public function test_agent_without_skills_has_clean_instructions(): void
    {
        $agent = new ManualSkillsAgent(new SkillRegistry);

        $this->assertStringNotContainsString('<available_skills>', $agent->instructions());
        $this->assertStringContainsString('You are a helpful assistant', $agent->instructions());
    }

    public function test_activate_skill_tool_returns_full_content(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'customer-support']));

        $this->assertStringContainsString('Customer Support Skill', $result);
        $this->assertStringContainsString('Priority levels', $result);
    }

    public function test_activate_skill_tool_includes_available_resources(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'customer-support']));

        $this->assertStringContainsString('<available_resources>', $result);
        $this->assertStringContainsString('scripts/triage.sh', $result);
        $this->assertStringContainsString('references/TEMPLATES.md', $result);
    }

    public function test_activate_skill_tool_omits_resources_when_none_exist(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ActivateSkillTool($registry);

        $result = $tool->handle(new Request(['name' => 'order-fulfillment']));

        $this->assertStringNotContainsString('<available_resources>', $result);
    }

    public function test_activate_skill_tool_throws_for_unknown_skill(): void
    {
        $tool = new ActivateSkillTool(new SkillRegistry);

        $this->expectException(SkillNotFoundException::class);
        $tool->handle(new Request(['name' => 'nonexistent']));
    }

    public function test_read_skill_resource_returns_file_content(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'scripts/triage.sh']));

        $this->assertStringContainsString('Running ticket triage', $result);
    }

    public function test_read_skill_resource_returns_reference_content(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'references/TEMPLATES.md']));

        $this->assertStringContainsString('Response Templates', $result);
        $this->assertStringContainsString('Thank you for contacting support', $result);
    }

    public function test_read_skill_resource_returns_not_found_for_missing_file(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => 'scripts/nonexistent.sh']));

        $this->assertSame('Resource not found.', $result);
    }

    public function test_read_skill_resource_prevents_path_traversal(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');
        $tool = new ReadSkillResourceTool($registry);

        $result = $tool->handle(new Request(['skill' => 'customer-support', 'path' => '../order-fulfillment/SKILL.md']));

        $this->assertSame('Resource not found.', $result);
    }

    public function test_manual_agent_exposes_skill_tools(): void
    {
        $agent = new ManualSkillsAgent(new SkillRegistry);

        $tools = iterator_to_array($agent->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(ActivateSkillTool::class, $tools[0]);
        $this->assertInstanceOf(ReadSkillResourceTool::class, $tools[1]);
    }

    public function test_loads_skills_trait_loads_from_skill_paths(): void
    {
        $agent = new FixtureSkillsAgent;

        $skills = $agent->skills();

        $this->assertCount(2, $skills);

        $names = array_map(fn (Skill $s) => $s->name, $skills);
        $this->assertContains('customer-support', $names);
        $this->assertContains('order-fulfillment', $names);
    }

    public function test_loads_skills_returns_empty_when_paths_empty(): void
    {
        $agent = new EmptyPathSkillsAgent;

        $this->assertEmpty($agent->skills());
    }

    public function test_loads_skills_returns_empty_for_nonexistent_paths(): void
    {
        $agent = new NonexistentPathSkillsAgent;

        $this->assertEmpty($agent->skills());
    }

    public function test_loads_skills_memoizes_within_instance(): void
    {
        $agent = new FixtureSkillsAgent;

        $first = $agent->skills();
        $second = $agent->skills();

        $this->assertSame($first, $second);
    }

    public function test_clear_skill_cache_resets_memoization(): void
    {
        $agent = new FixtureSkillsAgent;

        $first = $agent->skills();
        $agent->clearSkillCache();
        $second = $agent->skills();

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $second);
    }

    public function test_support_skills_agent_instructions_stay_clean(): void
    {
        $agent = new FixtureSkillsAgent;

        // Instructions should NOT contain skills — the SDK injects them automatically.
        $this->assertSame('You are a helpful assistant.', $agent->instructions());
    }

    public function test_agent_can_override_skills_entirely(): void
    {
        $loader = new SkillLoader;
        $skills = $loader->loadFromDirectory(__DIR__.'/Fixtures');

        $agent = new DirectSkillsAgent($skills);

        $this->assertCount(2, $agent->skills());
    }
}

/**
 * Manual approach — user manages registry and tools themselves.
 */
class ManualSkillsAgent implements Agent, HasTools
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

/**
 * Convention approach — uses LoadsSkills trait with a fixture path.
 * In production, defaults to resource_path('ai/skills').
 */
class FixtureSkillsAgent implements Agent, SupportSkills
{
    use LoadsSkills, Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    protected function skillPaths(): array
    {
        return [__DIR__.'/Fixtures'];
    }
}

/**
 * Agent whose skillPaths() returns an empty array — no skills loaded.
 */
class EmptyPathSkillsAgent implements Agent, SupportSkills
{
    use LoadsSkills, Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    protected function skillPaths(): array
    {
        return [];
    }
}

/**
 * Agent whose skillPaths() returns a path that doesn't exist.
 */
class NonexistentPathSkillsAgent implements Agent, SupportSkills
{
    use LoadsSkills, Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    protected function skillPaths(): array
    {
        return ['/tmp/nonexistent-skills-'.uniqid()];
    }
}

/**
 * Fully custom — load skills from DB, S3, or any source.
 * Just implement skills() directly, no trait needed.
 */
class DirectSkillsAgent implements Agent, SupportSkills
{
    use Promptable;

    public function __construct(protected array $loadedSkills = []) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function skills(): array
    {
        return $this->loadedSkills;
    }
}
