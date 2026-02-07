<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\Exceptions\SkillParseException;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Skills\SkillLoader;
use Tests\TestCase;

class SkillLoaderTest extends TestCase
{
    protected SkillLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new SkillLoader;
    }

    public function test_can_load_skill_from_directory(): void
    {
        $skill = $this->loader->load(__DIR__.'/Fixtures/customer-support');

        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertEquals('customer-support', $skill->name);
        $this->assertStringContainsString('Handle customer support inquiries', $skill->description);
        $this->assertEquals('MIT', $skill->license);
        $this->assertEquals('Requires access to the ticketing system', $skill->compatibility);
        $this->assertEquals('Bash(curl:*) Read', $skill->allowedTools);
        $this->assertEquals(['author' => 'test-author', 'version' => '1.0'], $skill->metadata);
        $this->assertStringContainsString('Customer Support Skill', $skill->content);
    }

    public function test_can_load_skill_with_minimal_frontmatter(): void
    {
        $skill = $this->loader->load(__DIR__.'/Fixtures/order-fulfillment');

        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertEquals('order-fulfillment', $skill->name);
        $this->assertStringContainsString('Process orders', $skill->description);
        $this->assertNull($skill->license);
        $this->assertEmpty($skill->metadata);
    }

    public function test_throws_exception_for_missing_skill_file(): void
    {
        $this->expectException(SkillParseException::class);
        $this->expectExceptionMessage('SKILL.md not found');

        $this->loader->load(__DIR__.'/Fixtures/nonexistent');
    }

    public function test_throws_exception_for_missing_required_name(): void
    {
        $this->expectException(SkillParseException::class);
        $this->expectExceptionMessage('Missing required field');

        // Create temporary skill without name
        $tempDir = sys_get_temp_dir().'/test-skill-'.uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir.'/SKILL.md', "---\ndescription: Test\n---\nContent");

        try {
            $this->loader->load($tempDir);
        } finally {
            unlink($tempDir.'/SKILL.md');
            rmdir($tempDir);
        }
    }

    public function test_can_load_multiple_skills_from_directory(): void
    {
        $skills = $this->loader->loadFromDirectory(__DIR__.'/Fixtures');

        $this->assertCount(2, $skills);
        $this->assertContainsOnlyInstancesOf(Skill::class, $skills);

        $skillNames = array_map(fn ($skill) => $skill->name, $skills);
        $this->assertContains('customer-support', $skillNames);
        $this->assertContains('order-fulfillment', $skillNames);
    }

    public function test_load_from_directory_returns_empty_array_for_nonexistent_directory(): void
    {
        $skills = $this->loader->loadFromDirectory(__DIR__.'/Fixtures/nonexistent');

        $this->assertIsArray($skills);
        $this->assertEmpty($skills);
    }

    public function test_skill_to_array_includes_all_fields(): void
    {
        $skill = $this->loader->load(__DIR__.'/Fixtures/customer-support');

        $array = $skill->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('license', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertEquals('customer-support', $array['name']);
        $this->assertEquals('MIT', $array['license']);
    }

    public function test_skill_to_xml_generates_proper_format(): void
    {
        $skill = $this->loader->load(__DIR__.'/Fixtures/customer-support');

        $xml = $skill->toXml();

        $this->assertStringContainsString('<skill>', $xml);
        $this->assertStringContainsString('<name>', $xml);
        $this->assertStringContainsString('customer-support', $xml);
        $this->assertStringContainsString('<description>', $xml);
        $this->assertStringContainsString('</skill>', $xml);
    }
}
