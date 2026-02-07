<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\Exceptions\SkillNotFoundException;
use Laravel\Ai\Skills\SkillLoader;
use Laravel\Ai\Skills\SkillRegistry;
use Tests\TestCase;

class SkillRegistryTest extends TestCase
{
    protected SkillRegistry $registry;

    protected SkillLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new SkillRegistry;
        $this->loader = new SkillLoader;
    }

    public function test_can_register_and_retrieve_skill(): void
    {
        $skill = $this->loader->load(__DIR__.'/Fixtures/customer-support');

        $this->registry->register($skill);

        $this->assertTrue($this->registry->has('customer-support'));
        $this->assertEquals($skill, $this->registry->find('customer-support'));
    }

    public function test_all_returns_all_registered_skills(): void
    {
        $skill1 = $this->loader->load(__DIR__.'/Fixtures/customer-support');
        $skill2 = $this->loader->load(__DIR__.'/Fixtures/order-fulfillment');

        $this->registry->register($skill1);
        $this->registry->register($skill2);

        $all = $this->registry->all();

        $this->assertCount(2, $all);
        $this->assertContains($skill1, $all);
        $this->assertContains($skill2, $all);
    }

    public function test_find_returns_null_for_nonexistent_skill(): void
    {
        $skill = $this->registry->find('nonexistent');

        $this->assertNull($skill);
    }

    public function test_find_or_fail_throws_exception_for_nonexistent_skill(): void
    {
        $this->expectException(SkillNotFoundException::class);
        $this->expectExceptionMessage('Skill [nonexistent] not found');

        $this->registry->findOrFail('nonexistent');
    }

    public function test_has_returns_false_for_nonexistent_skill(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function test_count_returns_correct_number(): void
    {
        $this->assertEquals(0, $this->registry->count());

        $skill1 = $this->loader->load(__DIR__.'/Fixtures/customer-support');
        $this->registry->register($skill1);

        $this->assertEquals(1, $this->registry->count());

        $skill2 = $this->loader->load(__DIR__.'/Fixtures/order-fulfillment');
        $this->registry->register($skill2);

        $this->assertEquals(2, $this->registry->count());
    }

    public function test_to_prompt_generates_xml_for_all_skills(): void
    {
        $skill1 = $this->loader->load(__DIR__.'/Fixtures/customer-support');
        $skill2 = $this->loader->load(__DIR__.'/Fixtures/order-fulfillment');

        $this->registry->register($skill1);
        $this->registry->register($skill2);

        $prompt = $this->registry->toPrompt();

        $this->assertStringContainsString('<available_skills>', $prompt);
        $this->assertStringContainsString('</available_skills>', $prompt);
        $this->assertStringContainsString('customer-support', $prompt);
        $this->assertStringContainsString('order-fulfillment', $prompt);
        $this->assertStringContainsString('<skill>', $prompt);
        $this->assertStringContainsString('</skill>', $prompt);
    }

    public function test_to_prompt_returns_empty_string_for_no_skills(): void
    {
        $prompt = $this->registry->toPrompt();

        $this->assertEquals('', $prompt);
    }

    public function test_from_directory_loads_all_skills(): void
    {
        $registry = SkillRegistry::fromDirectory(__DIR__.'/Fixtures');

        $this->assertCount(2, $registry->all());
        $this->assertTrue($registry->has('customer-support'));
        $this->assertTrue($registry->has('order-fulfillment'));
    }
}
