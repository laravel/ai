<?php

namespace Tests\Feature\Skills;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillDiscovery;
use Tests\TestCase;

class SkillDiscoveryTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir().'/ai_sdk_test_'.uniqid();
        File::makeDirectory($this->tempPath, 0755, true, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempPath);
        parent::tearDown();
    }

    public function test_it_discovers_skills_in_given_paths()
    {
        $skillDir = $this->tempPath.'/test-skill';
        File::makeDirectory($skillDir);
        File::put($skillDir.'/SKILL.md', <<<'MD'
---
name: Test Skill
description: A test skill description
---
Test instructions
MD
        );

        $discovery = new SkillDiscovery([$this->tempPath]);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(Skill::class, $skills->first());
        $this->assertEquals('Test Skill', $skills->first()->name);
    }

    public function test_it_rescans_on_every_discover_call()
    {
        $skillDir = $this->tempPath.'/test-skill';
        File::makeDirectory($skillDir);
        File::put($skillDir.'/SKILL.md', '---
name: Initial Skill
description: Description
---
Body');

        $discovery = new SkillDiscovery([$this->tempPath]);

        $this->assertEquals('Initial Skill', $discovery->discover()->first()->name);

        File::put($skillDir.'/SKILL.md', '---
name: Updated Skill
description: Description
---
Body');

        $this->assertEquals('Updated Skill', $discovery->discover()->first()->name);
    }

    public function test_resolve_finds_skill_by_name()
    {
        $skillDir = $this->tempPath.'/test-skill';
        File::makeDirectory($skillDir);
        File::put($skillDir.'/SKILL.md', '---
name: Target Skill
description: Description
---
Body');

        $discovery = new SkillDiscovery([$this->tempPath]);

        $skill = $discovery->resolve('Target Skill');
        $this->assertNotNull($skill);
        $this->assertEquals('Target Skill', $skill->name);

        $this->assertNull($discovery->resolve('Non Existent'));
    }

    public function test_returns_empty_collection_for_empty_paths()
    {
        $discovery = new SkillDiscovery([]);

        $this->assertTrue($discovery->discover()->isEmpty());
    }

    public function test_it_follows_symlinks_when_discovering_skills()
    {
        $targetDir = $this->tempPath.'/actual-skill';
        File::makeDirectory($targetDir);
        File::put($targetDir.'/SKILL.md', '---
name: Symlinked Skill
description: A symlinked skill
---
Instructions');

        $linksDir = sys_get_temp_dir().'/ai_sdk_links_'.uniqid();
        File::makeDirectory($linksDir);
        symlink($targetDir, $linksDir.'/linked-skill');

        $discovery = new SkillDiscovery([$linksDir]);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertEquals('Symlinked Skill', $skills->first()->name);

        unlink($linksDir.'/linked-skill');
        File::deleteDirectory($linksDir);
    }
}
