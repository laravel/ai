<?php

namespace Tests\Feature;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Gateway\Prism\PrismTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class NamedToolTest extends TestCase
{
    public function test_named_tool_uses_explicit_name(): void
    {
        $resolver = new TestToolResolver;
        $tool = new NamedToolExample;

        $prismTool = $resolver->make($tool);

        $this->assertSame('crm.create_ticket', $prismTool->name());
    }

    public function test_regular_tool_uses_class_basename(): void
    {
        $resolver = new TestToolResolver;
        $tool = new RegularToolExample;

        $prismTool = $resolver->make($tool);

        $this->assertSame('RegularToolExample', $prismTool->name());
    }
}

class TestToolResolver
{
    use AddsToolsToPrismRequests;

    public function make(Tool $tool): PrismTool
    {
        return $this->createPrismTool($tool);
    }
}

class NamedToolExample implements Tool
{
    public function name(): string
    {
        return 'crm.create_ticket';
    }

    public function description(): string
    {
        return 'Creates a ticket in CRM.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class RegularToolExample implements Tool
{
    public function description(): string
    {
        return 'Regular tool.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
