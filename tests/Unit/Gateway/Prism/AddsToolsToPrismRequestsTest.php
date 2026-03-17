<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Providers\Tools\ProviderTool;
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

            public function callInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->callInvokeTool($tool, ['schema_definition' => ['query' => 'test']]);

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

            public function callInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->callInvokeTool($tool, ['query' => 'test']);

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

            public function callInvokeTool(Tool $tool, array $arguments): string
            {
                return $this->invokeTool($tool, $arguments);
            }
        };

        $handler->callInvokeTool($tool, []);

        $this->assertEquals([], $tool->receivedArguments);
    }

    public function test_add_tools_does_not_set_tool_choice_when_only_provider_tools_are_present(): void
    {
        $providerTool = new class extends ProviderTool {};

        $request = new class
        {
            public array $tools = [];

            public bool $toolChoiceSet = false;

            public int $maxSteps = 0;

            public function withTools(array $tools): self
            {
                $this->tools = $tools;

                return $this;
            }

            public function withToolChoice($choice): self
            {
                $this->toolChoiceSet = true;

                return $this;
            }

            public function withMaxSteps(int $steps): self
            {
                $this->maxSteps = $steps;

                return $this;
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

            public function callAddTools($request, array $tools)
            {
                return $this->addTools($request, $tools);
            }
        };

        $handler->callAddTools($request, [$providerTool]);

        $this->assertEmpty($request->tools);
        $this->assertFalse($request->toolChoiceSet, 'withToolChoice should not be called when only ProviderTools are present');
    }

    public function test_add_tools_sets_tool_choice_when_regular_tools_are_present(): void
    {
        $tool = new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'Test tool';
            }

            public function handle(Request $request): Stringable|string
            {
                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $request = new class
        {
            public array $tools = [];

            public bool $toolChoiceSet = false;

            public int $maxSteps = 0;

            public function withTools(array $tools): self
            {
                $this->tools = $tools;

                return $this;
            }

            public function withToolChoice($choice): self
            {
                $this->toolChoiceSet = true;

                return $this;
            }

            public function withMaxSteps(int $steps): self
            {
                $this->maxSteps = $steps;

                return $this;
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

            public function callAddTools($request, array $tools)
            {
                return $this->addTools($request, $tools);
            }
        };

        $handler->callAddTools($request, [$tool]);

        $this->assertNotEmpty($request->tools);
        $this->assertTrue($request->toolChoiceSet, 'withToolChoice should be called when regular tools are present');
    }

    public function test_add_tools_sets_tool_choice_when_mixed_tools_are_present(): void
    {
        $tool = new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'Test tool';
            }

            public function handle(Request $request): Stringable|string
            {
                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $providerTool = new class extends ProviderTool {};

        $request = new class
        {
            public array $tools = [];

            public bool $toolChoiceSet = false;

            public int $maxSteps = 0;

            public function withTools(array $tools): self
            {
                $this->tools = $tools;

                return $this;
            }

            public function withToolChoice($choice): self
            {
                $this->toolChoiceSet = true;

                return $this;
            }

            public function withMaxSteps(int $steps): self
            {
                $this->maxSteps = $steps;

                return $this;
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

            public function callAddTools($request, array $tools)
            {
                return $this->addTools($request, $tools);
            }
        };

        $handler->callAddTools($request, [$tool, $providerTool]);

        $this->assertCount(1, $request->tools, 'Only the regular tool should be in prismTools');
        $this->assertTrue($request->toolChoiceSet, 'withToolChoice should be called when regular tools are present alongside ProviderTools');
    }
}
