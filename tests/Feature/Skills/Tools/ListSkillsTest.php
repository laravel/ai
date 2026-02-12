<?php

namespace Tests\Feature\Skills\Tools;

use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\ListSkills;
use Laravel\Ai\Tools\Request;
use Mockery;
use PHPUnit\Framework\TestCase;

class ListSkillsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_description_contains_available_skills_xml_block(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('discover')->once()->andReturn(collect([
            new Skill(
                name: 'git-master',
                description: 'Advanced git operations',
                instructions: '...',
                source: 'local',
            ),
            new Skill(
                name: 'playwright',
                description: 'Browser automation via Playwright',
                instructions: '...',
                source: 'community',
            ),
        ]));

        $tool = new ListSkills($registry);

        $description = (string) $tool->description();

        $this->assertStringContainsString('<available_skills>', $description);
        $this->assertStringContainsString('</available_skills>', $description);
        $this->assertStringContainsString('git-master', $description);
        $this->assertStringContainsString('Advanced git operations', $description);
        $this->assertStringContainsString('playwright', $description);
        $this->assertStringContainsString('Browser automation via Playwright', $description);
    }

    public function test_description_is_memoized(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('discover')->once()->andReturn(collect([
            new Skill(
                name: 'test-skill',
                description: 'A test skill',
                instructions: '...',
            ),
        ]));

        $tool = new ListSkills($registry);

        $first = $tool->description();
        $second = $tool->description();

        $this->assertSame((string) $first, (string) $second);
    }

    public function test_description_with_no_skills_still_valid(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('discover')->once()->andReturn(collect());

        $tool = new ListSkills($registry);

        $description = (string) $tool->description();

        $this->assertStringContainsString('<available_skills>', $description);
        $this->assertStringContainsString('</available_skills>', $description);
    }

    public function test_handle_returns_markdown_table_with_status(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('discover')->andReturn(collect([
            new Skill(
                name: 'loaded-skill',
                description: 'A loaded skill',
                instructions: '...',
                source: 'local',
            ),
            new Skill(
                name: 'available-skill',
                description: 'An available skill',
                instructions: '...',
                source: 'community',
            ),
        ]));

        $registry->shouldReceive('isLoaded')
            ->with('loaded-skill')
            ->andReturn(true);

        $registry->shouldReceive('isLoaded')
            ->with('available-skill')
            ->andReturn(false);

        $tool = new ListSkills($registry);

        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('| Name |', $result);
        $this->assertStringContainsString('| Description |', $result);
        $this->assertStringContainsString('| Source |', $result);
        $this->assertStringContainsString('| Status |', $result);
        $this->assertStringContainsString('|---', $result);
        $this->assertStringContainsString('loaded-skill', $result);
        $this->assertStringContainsString('available-skill', $result);
        $this->assertStringContainsString('Loaded', $result);
        $this->assertStringContainsString('Available', $result);
    }
}
