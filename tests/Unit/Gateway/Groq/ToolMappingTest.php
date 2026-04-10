<?php

namespace Tests\Unit\Gateway\Groq;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Groq\Concerns\MapsTools;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\TestCase;

class ToolMappingTest extends TestCase
{
    public function test_tool_parameters_are_not_wrapped_in_schema_definition(): void
    {
        $mapper = new class
        {
            use MapsTools;

            public function map(array $tools): array
            {
                return $this->mapTools($tools);
            }
        };

        $tool = new class implements Tool
        {
            public function description(): string
            {
                return 'Creates a new lead';
            }

            public function handle(Request $request): string
            {
                return 'done';
            }

            public function schema(JsonSchema $schema): array
            {
                return [
                    'name' => $schema->string()->required()->description('full customer name'),
                    'email' => $schema->string()->nullable(),
                    'phone_number' => $schema->string()->required()->description("customer's phone number"),
                ];
            }
        };

        $mapped = $mapper->map([$tool]);

        $parameters = $mapped[0]['function']['parameters'];

        // The parameters should have direct properties, not wrapped in schema_definition (fixes #239, #342)
        $this->assertArrayNotHasKey('schema_definition', $parameters['properties'] ?? []);
        $this->assertNotContains('schema_definition', $parameters['required'] ?? []);
        $this->assertArrayHasKey('name', $parameters['properties']);
        $this->assertArrayHasKey('email', $parameters['properties']);
        $this->assertArrayHasKey('phone_number', $parameters['properties']);
        $this->assertContains('name', $parameters['required']);
        $this->assertContains('phone_number', $parameters['required']);
        $this->assertEquals('object', $parameters['type']);
        $this->assertFalse($parameters['additionalProperties']);
    }

    public function test_tool_with_empty_schema_includes_parameters(): void
    {
        $mapper = new class
        {
            use MapsTools;

            public function map(array $tools): array
            {
                return $this->mapTools($tools);
            }
        };

        $tool = new class implements Tool
        {
            public function description(): string
            {
                return 'A tool with no parameters';
            }

            public function handle(Request $request): string
            {
                return 'done';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $mapped = $mapper->map([$tool]);

        $function = $mapped[0]['function'];

        $this->assertArrayHasKey('parameters', $function);
        $this->assertEquals('object', $function['parameters']['type']);
        $this->assertEquals([], $function['parameters']['required']);
        $this->assertFalse($function['parameters']['additionalProperties']);
    }
}
