<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\Skill;
use ReflectionClass;
use Tests\TestCase;

class SkillTest extends TestCase
{
    public function test_skill_slug_generation()
    {
        $skill = new Skill(
            name: 'My Coding Skill',
            description: 'Desc',
            instructions: 'Inst'
        );

        $this->assertSame('my-coding-skill', $skill->slug());
    }

    public function test_skill_is_immutable()
    {
        $this->assertTrue((new ReflectionClass(Skill::class))->isReadOnly());
    }

    public function test_reference_files_returns_supported_files_excluding_skill_md()
    {
        $tempPath = sys_get_temp_dir().'/skill-ref-test-'.uniqid();
        mkdir($tempPath);

        file_put_contents($tempPath.'/SKILL.md', 'skip');
        file_put_contents($tempPath.'/guide.md', 'content');
        file_put_contents($tempPath.'/config.yaml', 'content');
        file_put_contents($tempPath.'/data.json', 'content');
        file_put_contents($tempPath.'/notes.txt', 'content');
        file_put_contents($tempPath.'/image.png', 'content');

        $skill = new Skill(
            name: 'test',
            description: 'd',
            instructions: 'i',
            basePath: $tempPath
        );

        $files = $skill->referenceFiles();

        $this->assertContains('guide.md', $files);
        $this->assertContains('config.yaml', $files);
        $this->assertContains('data.json', $files);
        $this->assertContains('notes.txt', $files);
        $this->assertNotContains('SKILL.md', $files);
        $this->assertNotContains('image.png', $files);

        array_map('unlink', glob($tempPath.'/*'));
        rmdir($tempPath);
    }

    public function test_reference_files_returns_empty_when_no_base_path()
    {
        $skill = new Skill(name: 'test', description: 'd', instructions: 'i');

        $this->assertSame([], $skill->referenceFiles());
    }

    public function test_reference_files_returns_empty_when_directory_missing()
    {
        $skill = new Skill(
            name: 'test',
            description: 'd',
            instructions: 'i',
            basePath: '/nonexistent/path/'.uniqid()
        );

        $this->assertSame([], $skill->referenceFiles());
    }

    public function test_reference_files_discovers_files_in_subdirectories()
    {
        $tempPath = sys_get_temp_dir().'/skill-ref-subdir-'.uniqid();
        mkdir($tempPath);
        mkdir($tempPath.'/references');

        file_put_contents($tempPath.'/SKILL.md', 'skip');
        file_put_contents($tempPath.'/guide.md', 'root file');
        file_put_contents($tempPath.'/references/utilities.md', 'content');
        file_put_contents($tempPath.'/references/theme.md', 'content');

        $skill = new Skill(
            name: 'test',
            description: 'd',
            instructions: 'i',
            basePath: $tempPath
        );

        $files = $skill->referenceFiles();

        $this->assertContains('guide.md', $files);
        $this->assertContains('references/utilities.md', $files);
        $this->assertContains('references/theme.md', $files);
        $this->assertNotContains('SKILL.md', $files);

        array_map('unlink', glob($tempPath.'/references/*'));
        rmdir($tempPath.'/references');
        array_map('unlink', glob($tempPath.'/*'));
        rmdir($tempPath);
    }
}
