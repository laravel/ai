<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Console\Commands\SkillsListCommand;
use Tests\TestCase;

class SkillsListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::registerCommand($this->app->make(SkillsListCommand::class));
    }

    public function test_shows_message_when_no_skills_found(): void
    {
        $this->app['config']->set('ai.skills.paths', []);

        $this->artisan('skill:list')
            ->expectsOutputToContain('No skills found.')
            ->assertExitCode(0);
    }

    public function test_lists_discovered_skills_as_table(): void
    {
        $tmpDir = sys_get_temp_dir().'/laravel-ai-test-skills/test-skill';

        File::makeDirectory($tmpDir, 0755, true, true);
        File::put($tmpDir.'/SKILL.md', <<<'MD'
---
name: Test Skill
description: A test skill
---
Some instructions here.
MD);

        $this->app['config']->set('ai.skills.paths', [dirname($tmpDir)]);

        $this->artisan('skill:list')
            ->expectsOutputToContain('Test Skill')
            ->assertExitCode(0);

        File::deleteDirectory(dirname($tmpDir));
    }
}
