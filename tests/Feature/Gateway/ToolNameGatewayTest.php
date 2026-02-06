<?php

namespace Tests\Feature\Gateway;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Gateway\Prism\PrismTool;
use Tests\Feature\Tools\NamedTool;
use Tests\Feature\Tools\RandomNumberGenerator;
use Tests\TestCase;

class ToolNameGatewayTest extends TestCase
{
    protected function createPrismTool(Tool $tool): PrismTool
    {
        return (new class
        {
            use AddsToolsToPrismRequests;

            protected $invokingToolCallback;

            protected $toolInvokedCallback;

            public function __construct()
            {
                $this->invokingToolCallback = fn () => true;
                $this->toolInvokedCallback = fn () => true;
            }

            public function createTool(Tool $tool): PrismTool
            {
                return $this->createPrismTool($tool);
            }
        })->createTool($tool);
    }

    public function test_it_uses_custom_name_from_trait(): void
    {
        $tool = (new NamedTool)->as('custom_name');
        $prismTool = $this->createPrismTool($tool);

        $this->assertEquals('custom_name', $prismTool->name());
    }

    public function test_it_falls_back_to_class_basename_without_custom_name(): void
    {
        $tool = new RandomNumberGenerator;
        $prismTool = $this->createPrismTool($tool);

        $this->assertStringContainsString('RandomNumberGenerator', $prismTool->name());
    }

    public function test_it_preserves_tool_description(): void
    {
        $tool = new NamedTool;
        $prismTool = $this->createPrismTool($tool);

        $this->assertEquals('This is a tool with a custom name.', $prismTool->description());
    }

    public function test_it_preserves_tool_schema(): void
    {
        $tool = new RandomNumberGenerator;
        $prismTool = $this->createPrismTool($tool);

        $this->assertTrue($prismTool->hasParameters());
        $this->assertArrayHasKey('schema_definition', $prismTool->parameters());
    }
}
