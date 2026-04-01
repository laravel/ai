<?php

namespace Tests\Unit\Gateway\Xai;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Xai\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\TestCase;

class ToolMappingTest extends TestCase
{
    public function test_tool_parameters_are_not_wrapped_in_schema_definition(): void
    {
        $mapper = new class
        {
            use MapsTools;

            public function map(array $tools, Provider $provider): array
            {
                return $this->mapTools($tools, $provider);
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

            public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
            {
                return [
                    'name' => $schema->string()->required()->description('full customer name'),
                    'email' => $schema->string()->nullable(),
                    'phone_number' => $schema->string()->required()->description("customer's phone number"),
                ];
            }
        };

        $provider = $this->createMock(Provider::class);

        $mapped = $mapper->map([$tool], $provider);

        $parameters = $mapped[0]['parameters'];

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

            public function map(array $tools, Provider $provider): array
            {
                return $this->mapTools($tools, $provider);
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

            public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
            {
                return [];
            }
        };

        $provider = $this->createMock(Provider::class);

        $mapped = $mapper->map([$tool], $provider);

        $this->assertArrayHasKey('parameters', $mapped[0]);
        $this->assertEquals('object', $mapped[0]['parameters']['type']);
        $this->assertEquals([], $mapped[0]['parameters']['required']);
        $this->assertFalse($mapped[0]['parameters']['additionalProperties']);
    }
}
