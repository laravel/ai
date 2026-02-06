<?php

namespace Tests\Feature;

use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\NamedTool;
use Tests\TestCase;

class ToolCustomNameTest extends TestCase
{
    public function test_it_defaults_to_class_basename(): void
    {
        $tool = new NamedTool;

        $this->assertStringContainsString('NamedTool', $tool->name());
    }

    public function test_it_allows_custom_name(): void
    {
        $tool = (new NamedTool)->as('custom_name');

        $this->assertEquals('custom_name', $tool->name());
    }

    public function test_it_returns_same_instance(): void
    {
        $tool = new NamedTool;

        $this->assertSame($tool, $tool->as('custom_name'));
    }

    public function test_it_supports_multiple_instances_with_different_names(): void
    {
        $tools = [
            (new NamedTool)->as('search_products'),
            (new NamedTool)->as('search_categories'),
        ];

        $this->assertEquals('search_products', $tools[0]->name());
        $this->assertEquals('search_categories', $tools[1]->name());
    }

    public function test_tool_without_trait_does_not_have_name_method(): void
    {
        $tool = new FixedNumberGenerator;

        $this->assertFalse(method_exists($tool, 'name'));
    }

    public function test_it_preserves_description(): void
    {
        $tool = (new NamedTool)->as('my_search_tool');

        $this->assertEquals('my_search_tool', $tool->name());
        $this->assertEquals('This is a tool with a custom name.', $tool->description());
    }
}
