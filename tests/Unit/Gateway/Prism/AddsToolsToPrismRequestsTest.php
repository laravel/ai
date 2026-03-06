<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\TestCase;
use Stringable;

class AddsToolsToPrismRequestsTest extends TestCase
{
    public function test_invoke_tool_unwraps_schema_definition(): void
    {
        $tool = new class implements Tool
        {
            public array $receivedArguments = [];

            public function description(): Stringable|string
            {
                return 'Test tool';
            }

            public function handle(Request $request): Stringable|string
            {
                $this->receivedArguments = $request->all();

                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $handler = new class
        {
            use AddsToolsToPrismRequests;

            protected $invokingToolCallback;

            protected $toolInvokedCallback;

            public function __construct()
            {
                $this->invokingToolCallback = fn () => null;
                $this->toolInvokedCallback = fn () => null;
            }

            public function testInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->testInvokeTool($tool, ['schema_definition' => ['query' => 'test']]);

        $this->assertEquals(['query' => 'test'], $tool->receivedArguments);
    }

    public function test_invoke_tool_falls_back_to_raw_arguments_when_schema_definition_is_missing(): void
    {
        $tool = new class implements Tool
        {
            public array $receivedArguments = [];

            public function description(): Stringable|string
            {
                return 'Test tool';
            }

            public function handle(Request $request): Stringable|string
            {
                $this->receivedArguments = $request->all();

                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $handler = new class
        {
            use AddsToolsToPrismRequests;

            protected $invokingToolCallback;

            protected $toolInvokedCallback;

            public function __construct()
            {
                $this->invokingToolCallback = fn () => null;
                $this->toolInvokedCallback = fn () => null;
            }

            public function testInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->testInvokeTool($tool, ['query' => 'test']);

        $this->assertEquals(['query' => 'test'], $tool->receivedArguments);
    }

    public function test_invoke_tool_handles_empty_arguments(): void
    {
        $tool = new class implements Tool
        {
            public array $receivedArguments = [];

            public function description(): Stringable|string
            {
                return 'Test tool';
            }

            public function handle(Request $request): Stringable|string
            {
                $this->receivedArguments = $request->all();

                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $handler = new class
        {
            use AddsToolsToPrismRequests;

            protected $invokingToolCallback;

            protected $toolInvokedCallback;

            public function __construct()
            {
                $this->invokingToolCallback = fn () => null;
                $this->toolInvokedCallback = fn () => null;
            }

            public function testInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->testInvokeTool($tool, []);

        $this->assertEquals([], $tool->receivedArguments);
    }
}
