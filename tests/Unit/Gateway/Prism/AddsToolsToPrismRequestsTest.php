<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Deferred;
use Laravel\Ai\Contracts\ShouldDeferTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\DeferredToolManager;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\TestCase;
use Stringable;

class AddsToolsToPrismRequestsTest extends TestCase
{
    public function test_invoke_tool_unwraps_schema_definition(): void
    {
        $tool = new TestRecordingTool;

        $this->handler()->invokeToolForTest($tool, ['schema_definition' => ['query' => 'test']]);

        $this->assertSame(['query' => 'test'], $tool->receivedArguments);
    }

    public function test_invoke_tool_falls_back_to_raw_arguments_when_schema_definition_is_missing(): void
    {
        $tool = new TestRecordingTool;

        $this->handler()->invokeToolForTest($tool, ['query' => 'test']);

        $this->assertSame(['query' => 'test'], $tool->receivedArguments);
    }

    public function test_invoke_tool_handles_empty_arguments(): void
    {
        $tool = new TestRecordingTool;

        $this->handler()->invokeToolForTest($tool, []);

        $this->assertSame([], $tool->receivedArguments);
    }

    public function test_invoke_tool_returns_pending_payload_when_tool_implements_should_defer_tool(): void
    {
        $tool = new class implements ShouldDeferTool, Tool
        {
            public bool $handled = false;

            public function description(): Stringable|string
            {
                return 'Deferred tool';
            }

            public function handle(Request $request): Stringable|string
            {
                $this->handled = true;

                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $manager = new TestDeferredToolManager;
        $result = $this->handler($manager)->invokeToolForTest($tool, ['query' => 'test']);

        $this->assertFalse($tool->handled);
        $this->assertSame(['query' => 'test'], $manager->arguments);
        $this->assertJsonStringEqualsJsonString('{"status":"pending","tool_call_id":"call_1"}', $result);
    }

    public function test_should_defer_tool_returns_true_for_deferred_attribute(): void
    {
        $tool = new #[Deferred] class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'Deferred tool';
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

        $this->assertTrue($this->handler()->shouldDeferToolForTest($tool));
    }

    public function test_should_defer_tool_returns_false_for_standard_tools(): void
    {
        $tool = new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'Standard tool';
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

        $this->assertFalse($this->handler()->shouldDeferToolForTest($tool));
    }

    private function handler(?DeferredToolManager $manager = null): TestAddsToolsToPrismRequestsHandler
    {
        return new TestAddsToolsToPrismRequestsHandler($manager ?? new TestDeferredToolManager);
    }
}

class TestAddsToolsToPrismRequestsHandler
{
    use AddsToolsToPrismRequests;

    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    public function __construct(private readonly DeferredToolManager $manager)
    {
        $this->invokingToolCallback = fn () => null;
        $this->toolInvokedCallback = fn () => null;
    }

    protected function deferredToolManager(): DeferredToolManager
    {
        return $this->manager;
    }

    public function invokeToolForTest(Tool $tool, array $arguments): string
    {
        return $this->invokeTool($tool, $arguments);
    }

    public function shouldDeferToolForTest(Tool $tool): bool
    {
        return $this->shouldDeferTool($tool);
    }
}

class TestRecordingTool implements Tool
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
}

class TestDeferredToolManager extends DeferredToolManager
{
    public array $arguments = [];

    public function __construct() {}

    public function defer(Tool $tool, array $arguments): array
    {
        $this->arguments = $arguments;

        return [
            'status' => 'pending',
            'tool_call_id' => 'call_1',
        ];
    }

    public function resume(Tool|string $tool, array $arguments, string $toolCallId): mixed
    {
        return null;
    }
}
