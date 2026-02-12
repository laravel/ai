<?php

namespace Tests\Feature\Skills\Tools;

use FilesystemIterator;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\SkillReferenceReader;
use Laravel\Ai\Tools\Request;
use Mockery;
use Orchestra\Testbench\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SkillReferenceReaderTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir().'/ai_sdk_test_'.uniqid();
        if (! is_dir($this->tempPath)) {
            mkdir($this->tempPath);
        }
        file_put_contents($this->tempPath.'/test.txt', 'Test content');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempPath)) {
            $this->deleteDirectory($this->tempPath);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    public function test_reads_valid_file_within_skill_directory(): void
    {
        $skill = new Skill(
            name: 'valid-skill',
            description: 'Valid',
            instructions: 'Instructions',
            basePath: $this->tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('valid-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'valid-skill',
            'file' => 'test.txt',
        ]));

        $this->assertSame('Test content', (string) $result);
    }

    public function test_blocks_directory_traversal(): void
    {
        $skill = new Skill(
            name: 'valid-skill',
            description: 'Valid',
            instructions: 'Instructions',
            basePath: $this->tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('valid-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'valid-skill',
            'file' => '../outside.txt',
        ]));

        $this->assertStringContainsString('not found', (string) $result);
    }

    public function test_returns_error_for_unloaded_skill(): void
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('unknown-skill')->andReturn(null);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'unknown-skill',
            'file' => 'test.txt',
        ]));

        $this->assertStringContainsString('Skill \'unknown-skill\' not loaded', (string) $result);
    }

    public function test_returns_error_for_skill_without_base_path(): void
    {
        $skill = new Skill(
            name: 'no-path-skill',
            description: 'No Path',
            instructions: 'Instructions',
            basePath: null,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('no-path-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'no-path-skill',
            'file' => 'test.txt',
        ]));

        $this->assertStringContainsString('does not have a base path', (string) $result);
    }

    public function test_returns_error_for_nonexistent_file(): void
    {
        $skill = new Skill(
            name: 'valid-skill',
            description: 'Valid',
            instructions: 'Instructions',
            basePath: $this->tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('valid-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'valid-skill',
            'file' => 'does_not_exist.txt',
        ]));

        $this->assertStringContainsString('not found', (string) $result);
    }

    public function test_blocks_reading_non_reference_files(): void
    {
        // Use .log extension which is not in the allowed list (md, txt, yaml, yml, json)
        file_put_contents($this->tempPath.'/secret.log', 'Log content');

        $skill = new Skill(
            name: 'valid-skill',
            description: 'Valid',
            instructions: 'Instructions',
            basePath: $this->tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('valid-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'valid-skill',
            'file' => 'secret.log',
        ]));
        $this->assertStringContainsString('not in the allowed reference files list', (string) $result);
    }

    public function test_reads_files_from_subdirectories(): void
    {
        mkdir($this->tempPath.'/references');
        file_put_contents($this->tempPath.'/references/utilities.md', 'Utility reference content');

        $skill = new Skill(
            name: 'valid-skill',
            description: 'Valid',
            instructions: 'Instructions',
            basePath: $this->tempPath,
        );

        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('get')->with('valid-skill')->andReturn($skill);

        $tool = new SkillReferenceReader($registry);

        $result = $tool->handle(new Request([
            'skill' => 'valid-skill',
            'file' => 'references/utilities.md',
        ]));

        $this->assertSame('Utility reference content', (string) $result);
    }
}
