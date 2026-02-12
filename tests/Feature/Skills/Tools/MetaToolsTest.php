<?php

namespace Tests\Feature\Skills\Tools;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\ListSkills;
use Laravel\Ai\Skills\Tools\SkillLoader;
use Laravel\Ai\Skills\Tools\SkillReferenceReader;
use Laravel\Ai\Tools\Request;
use Mockery;
use Orchestra\Testbench\TestCase;

class MetaToolsTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/ai_sdk_test_'.uniqid();

        if (! is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0777, true);
        }

        file_put_contents($this->tempPath.'/test.txt', 'secret content');
        file_put_contents($this->tempPath.'/outside.txt', 'forbidden content');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_list_skills_returns_all_available_skills()
    {
        $skills = collect([
            new Skill(
                name: 'test-skill',
                description: 'A test skill',
                instructions: 'Do things',
                source: 'local'
            ),
            new Skill(
                name: 'another-skill',
                description: 'Another skill',
                instructions: 'Do other things',
                source: 'community'
            ),
        ]);

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('discover')->andReturn($skills);
        $registry->shouldReceive('isLoaded')->andReturn(false);

        $tool = new ListSkills($registry);

        $result = $tool->handle(new Request([]));

        $this->assertStringContainsString('test-skill', (string) $result);
        $this->assertStringContainsString('A test skill', (string) $result);
        $this->assertStringContainsString('another-skill', (string) $result);
        $this->assertStringContainsString('local', (string) $result);
        $this->assertStringContainsString('community', (string) $result);
    }

    public function test_skill_loader_loads_specific_skill()
    {
        $skill = new Skill(
            name: 'target-skill',
            description: 'Target skill',
            instructions: 'Target instructions',
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')
            ->with('target-skill', Mockery::any())
            ->andReturn($skill);

        $tool = new SkillLoader($registry);

        $result = $tool->handle(new Request(['skill' => 'target-skill']));

        $this->assertStringContainsString('<skill name="target-skill">', (string) $result);
        $this->assertStringContainsString('Target instructions', (string) $result);
        $this->assertStringContainsString('<instructions>', (string) $result);
    }

    public function test_skill_loader_returns_error_if_skill_not_found()
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')
            ->with('missing-skill', Mockery::any())
            ->andReturn(null);

        $tool = new SkillLoader($registry);

        $result = $tool->handle(new Request(['skill' => 'missing-skill']));

        $this->assertStringContainsString('not found', (string) $result);
    }

    public function test_skill_reference_reader_reads_file_within_skill_path()
    {
        $skill = new Skill(
            name: 'fs-skill',
            description: 'FS Skill',
            instructions: '...',
            basePath: $this->tempPath
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('fs-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'fs-skill',
            'file' => 'test.txt',
        ]));

        $this->assertEquals('secret content', (string) $result);
    }

    public function test_skill_reference_reader_blocks_path_traversal()
    {
        $skill = new Skill(
            name: 'fs-skill',
            description: 'FS Skill',
            instructions: '...',
            basePath: $this->tempPath
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('fs-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'fs-skill',
            'file' => '../outside.txt',
        ]));

        $this->assertStringContainsString('not found', (string) $result);
    }

    public function test_skill_reference_reader_handles_missing_files()
    {
        $skill = new Skill(
            name: 'fs-skill',
            description: 'FS Skill',
            instructions: '...',
            basePath: $this->tempPath
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('fs-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'fs-skill',
            'file' => 'does_not_exist.txt',
        ]));

        $this->assertStringContainsString('not found', (string) $result);
    }
}
