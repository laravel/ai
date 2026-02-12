<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillMode;
use Laravel\Ai\Skills\SkillRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_it_can_load_a_skill()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $skill = new Skill('test-skill', 'Description', 'Instructions');

        $discovery->shouldReceive('resolve')->with('test-skill')->once()->andReturn($skill);

        $registry = new SkillRegistry($discovery);

        $this->assertFalse($registry->isLoaded('test-skill'));

        $loadedSkill = $registry->load('test-skill');

        $this->assertSame($skill, $loadedSkill);
        $this->assertTrue($registry->isLoaded('test-skill'));
        $this->assertSame($skill, $registry->get('test-skill'));
    }

    public function test_it_returns_null_when_skill_cannot_be_resolved()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $discovery->shouldReceive('resolve')->with('unknown')->once()->andReturn(null);

        $registry = new SkillRegistry($discovery);

        $this->assertNull($registry->load('unknown'));
        $this->assertFalse($registry->isLoaded('unknown'));
    }

    public function test_it_can_get_all_loaded_skills()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $skill1 = new Skill('skill-1', 'Desc 1', 'Instr 1');
        $skill2 = new Skill('skill-2', 'Desc 2', 'Instr 2');

        $discovery->shouldReceive('resolve')->with('skill-1')->andReturn($skill1);
        $discovery->shouldReceive('resolve')->with('skill-2')->andReturn($skill2);

        $registry = new SkillRegistry($discovery);
        $registry->load('skill-1');
        $registry->load('skill-2');

        $loaded = $registry->getLoaded();

        $this->assertCount(2, $loaded);
        $this->assertArrayHasKey('skill-1', $loaded);
        $this->assertArrayHasKey('skill-2', $loaded);
    }

    public function test_it_generates_instructions_xml_in_full_mode()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $skill = new Skill('test-skill', 'Description', 'Instructions');

        $discovery->shouldReceive('resolve')->with('test-skill')->andReturn($skill);

        $registry = new SkillRegistry($discovery);
        $registry->load('test-skill');

        $expected = '<skill name="test-skill">'.PHP_EOL.'Instructions'.PHP_EOL.'</skill>';
        $this->assertEquals($expected, $registry->instructions('full'));
    }

    public function test_it_generates_instructions_xml_in_lite_mode()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $skill = new Skill('test-skill', 'Description', 'Instructions');

        $discovery->shouldReceive('resolve')->with('test-skill')->andReturn($skill);

        $registry = new SkillRegistry($discovery);
        $registry->load('test-skill');

        $expected = '<skill name="test-skill" description="Description" />';
        $this->assertEquals($expected, $registry->instructions('lite'));
    }

    public function test_it_prioritizes_specific_skill_mode_over_global_mode()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $fullSkill = new Skill('full-skill', 'Full Desc', 'Full Instr');
        $liteSkill = new Skill('lite-skill', 'Lite Desc', 'Lite Instr');
        $defaultSkill = new Skill('default-skill', 'Default Desc', 'Default Instr');

        $discovery->shouldReceive('resolve')->with('full-skill')->andReturn($fullSkill);
        $discovery->shouldReceive('resolve')->with('lite-skill')->andReturn($liteSkill);
        $discovery->shouldReceive('resolve')->with('default-skill')->andReturn($defaultSkill);

        $registry = new SkillRegistry($discovery);

        // Load with specific modes
        $registry->load('full-skill', 'full');
        $registry->load('lite-skill', 'lite');
        // Load without specific mode
        $registry->load('default-skill');

        // Request global mode as 'lite'
        // full-skill should stay full
        // lite-skill should stay lite
        // default-skill should be lite (from global)
        $xml = $registry->instructions('lite');

        $this->assertStringContainsString('<skill name="full-skill">'.PHP_EOL.'Full Instr'.PHP_EOL.'</skill>', $xml);
        $this->assertStringContainsString('<skill name="lite-skill" description="Lite Desc" />', $xml);
        $this->assertStringContainsString('<skill name="default-skill" description="Default Desc" />', $xml);

        // Request global mode as 'full'
        // full-skill should stay full
        // lite-skill should stay lite
        // default-skill should be full (from global)
        $xmlFull = $registry->instructions('full');

        $this->assertStringContainsString('<skill name="full-skill">'.PHP_EOL.'Full Instr'.PHP_EOL.'</skill>', $xmlFull);
        $this->assertStringContainsString('<skill name="lite-skill" description="Lite Desc" />', $xmlFull);
        $this->assertStringContainsString('<skill name="default-skill">'.PHP_EOL.'Default Instr'.PHP_EOL.'</skill>', $xmlFull);
    }

    public function test_none_mode_returns_empty_string()
    {
        $discovery = Mockery::mock('Laravel\Ai\Skills\SkillDiscovery');
        $skill = new Skill('test-skill', 'Description', 'Instructions');

        $discovery->shouldReceive('resolve')->with('test-skill')->andReturn($skill);

        $registry = new SkillRegistry($discovery);
        $registry->load('test-skill');

        $this->assertSame('', $registry->instructions(SkillMode::None));
    }
}
