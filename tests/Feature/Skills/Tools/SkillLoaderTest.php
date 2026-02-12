<?php

namespace Tests\Feature\Skills\Tools;

use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\SkillLoader;
use Laravel\Ai\Tools\Request;
use Mockery;
use PHPUnit\Framework\TestCase;

class SkillLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_handle_returns_xml_structured_output(): void
    {
        $skill = new Skill(
            name: 'git-master',
            description: 'Advanced git operations',
            instructions: 'Use git commands wisely.',
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->with('git-master', Mockery::any())->once()->andReturn($skill);

        $tool = new SkillLoader($registry);

        $result = (string) $tool->handle(new Request(['skill' => 'git-master']));

        $this->assertStringContainsString('<skill name="git-master">', $result);
        $this->assertStringContainsString('<instructions>', $result);
        $this->assertStringContainsString('Use git commands wisely.', $result);
        $this->assertStringContainsString('</instructions>', $result);
        $this->assertStringContainsString('</skill>', $result);
    }

    public function test_handle_includes_reference_files_with_skill_read_guidance(): void
    {
        $tempPath = sys_get_temp_dir().'/skill-loader-test-'.uniqid();
        mkdir($tempPath, 0777, true);

        file_put_contents($tempPath.'/guide.md', '# Guide');
        file_put_contents($tempPath.'/config.yaml', 'key: value');

        $skill = new Skill(
            name: 'my-skill',
            description: 'A skill with references',
            instructions: 'Follow the guide.',
            basePath: $tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->with('my-skill', Mockery::any())->once()->andReturn($skill);

        $tool = new SkillLoader($registry);

        $result = (string) $tool->handle(new Request(['skill' => 'my-skill']));

        $this->assertStringContainsString('<skill_references>', $result);
        $this->assertStringContainsString('</skill_references>', $result);
        $this->assertStringContainsString('guide.md', $result);
        $this->assertStringContainsString('config.yaml', $result);
        $this->assertStringContainsString('skill_read', $result);

        array_map('unlink', glob($tempPath.'/*'));
        rmdir($tempPath);
    }

    public function test_handle_omits_references_block_when_no_files(): void
    {
        $skill = new Skill(
            name: 'simple-skill',
            description: 'No references',
            instructions: 'Just do it.',
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->with('simple-skill', Mockery::any())->once()->andReturn($skill);

        $tool = new SkillLoader($registry);

        $result = (string) $tool->handle(new Request(['skill' => 'simple-skill']));

        $this->assertStringContainsString('<skill name="simple-skill">', $result);
        $this->assertStringContainsString('Just do it.', $result);
        $this->assertStringNotContainsString('<skill_references>', $result);
        $this->assertStringNotContainsString('</skill_references>', $result);
    }

    public function test_handle_returns_not_found_for_unknown_skill(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->with('nonexistent', Mockery::any())->once()->andReturn(null);

        $tool = new SkillLoader($registry);

        $result = (string) $tool->handle(new Request(['skill' => 'nonexistent']));

        $this->assertSame("Skill 'nonexistent' not found.", $result);
    }
}
