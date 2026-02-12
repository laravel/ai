<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MakeSkillCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (File::exists(resource_path('skills/test-skill'))) {
            File::deleteDirectory(resource_path('skills/test-skill'));
        }
    }

    public function test_can_create_a_skill_file(): void
    {
        $this->artisan('make:skill', [
            'name' => 'test-skill',
        ])->assertExitCode(0);

        $this->assertFileExists(resource_path('skills/test-skill/SKILL.md'));

        $content = File::get(resource_path('skills/test-skill/SKILL.md'));
        $this->assertStringContainsString('name: Test Skill', $content);
    }

    public function test_can_create_a_skill_with_force_option(): void
    {
        File::makeDirectory(resource_path('skills/test-skill'), 0755, true);
        File::put(resource_path('skills/test-skill/SKILL.md'), 'old content');

        $this->artisan('make:skill', [
            'name' => 'test-skill',
            '--force' => true,
        ])->assertExitCode(0);

        $content = File::get(resource_path('skills/test-skill/SKILL.md'));
        $this->assertStringContainsString('name: Test Skill', $content);
        $this->assertStringNotContainsString('old content', $content);
    }
}
