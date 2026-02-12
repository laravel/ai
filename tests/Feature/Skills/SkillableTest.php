<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skillable;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillMode;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Skills\Tools\ListSkills;
use Laravel\Ai\Skills\Tools\SkillLoader;
use Laravel\Ai\Skills\Tools\SkillReferenceReader;
use Mockery;
use Tests\TestCase;

class SkillableTest extends TestCase
{
    public function test_it_can_resolve_skills_from_array()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return [
                    'coach',
                    '/path/to/skill',
                    'writer' => SkillMode::Full,
                    'editor' => 'lite',
                ];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->with('coach', null)->once()->andReturn(new Skill('coach', 'desc', 'instr'));
        $registry->shouldReceive('load')->with('/path/to/skill', null)->once()->andReturn(new Skill('path-skill', 'desc', 'instr'));
        $registry->shouldReceive('load')->with('writer', SkillMode::Full)->once()->andReturn(new Skill('writer', 'desc', 'instr'));
        $registry->shouldReceive('load')->with('editor', 'lite')->once()->andReturn(new Skill('editor', 'desc', 'instr'));
        $registry->shouldReceive('instructions')->with(null)->once()->andReturn('');

        // Trigger lazy loading
        $skillable->skillInstructions();
    }

    public function test_it_returns_skill_instructions()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return ['coach'];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->with('coach', null)->once();

        // Default mode is Lite if not specified, but usually we pass it
        $registry->shouldReceive('instructions')->with(SkillMode::Full)->once()->andReturn('full instructions');
        $registry->shouldReceive('instructions')->with(SkillMode::Lite)->once()->andReturn('lite instructions');

        $this->assertEquals('full instructions', $skillable->skillInstructions(SkillMode::Full));
        $this->assertEquals('lite instructions', $skillable->skillInstructions(SkillMode::Lite));
    }

    public function test_it_boots_skills_only_once()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return ['coach'];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->with('coach', null)->once();
        $registry->shouldReceive('instructions')->twice()->andReturn('instructions');

        $skillable->skillInstructions();
        $skillable->skillInstructions();
    }

    public function test_it_returns_empty_with_no_skills()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return [];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->never();
        $registry->shouldReceive('instructions')->once()->andReturn('');

        $this->assertSame('', $skillable->skillInstructions());
    }

    public function test_skill_tools_returns_three_meta_tools()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return [];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->never();

        $tools = $skillable->skillTools();

        $this->assertCount(3, $tools);
        $this->assertInstanceOf(ListSkills::class, $tools[0]);
        $this->assertInstanceOf(SkillLoader::class, $tools[1]);
        $this->assertInstanceOf(SkillReferenceReader::class, $tools[2]);
    }

    public function test_skill_tools_boots_skills_before_returning()
    {
        $skillable = new class
        {
            use Skillable;

            public function skills(): iterable
            {
                return ['coach', 'writer' => SkillMode::Full];
            }
        };

        $registry = Mockery::mock(SkillRegistry::class);
        $this->app->instance(SkillRegistry::class, $registry);

        $registry->shouldReceive('load')->with('coach', null)->once();
        $registry->shouldReceive('load')->with('writer', SkillMode::Full)->once();

        $tools = $skillable->skillTools();

        $this->assertCount(3, $tools);
    }
}
