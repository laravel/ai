<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Tools\McpServer;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool as PrismToolDefinition;
use RuntimeException;

class McpServerToolIntegrationTest extends TestCase
{
    public function test_add_tools_passes_through_prism_tools(): void
    {
        $tool = (new PrismToolDefinition)
            ->as('lookup')
            ->for('Lookup data')
            ->using(fn (): string => 'ok');

        $request = new class
        {
            public array $tools = [];

            public mixed $toolChoice = null;

            public mixed $maxSteps = null;

            public function withTools(array $tools): self
            {
                $this->tools = $tools;

                return $this;
            }

            public function withToolChoice($toolChoice): self
            {
                $this->toolChoice = $toolChoice;

                return $this;
            }

            public function withMaxSteps($maxSteps): self
            {
                $this->maxSteps = $maxSteps;

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

            public function test_add_tools($request, array $tools, ?TextGenerationOptions $options = null): mixed
            {
                return $this->addTools($request, $tools, $options);
            }
        };

        $handler->test_add_tools($request, [$tool]);

        $this->assertSame([$tool], $request->tools);
        $this->assertSame(ToolChoice::Auto, $request->toolChoice);
        $this->assertSame(2.0, $request->maxSteps);
    }

    public function test_mcp_server_tool_throws_helpful_exception_without_relay_package(): void
    {
        if (class_exists(\Prism\Relay\RelayFactory::class)) {
            $this->markTestSkipped('Relay is installed in this environment.');
        }

        $request = new class
        {
            public function withTools(array $tools): self
            {
                return $this;
            }

            public function withToolChoice($toolChoice): self
            {
                return $this;
            }

            public function withMaxSteps($maxSteps): self
            {
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

            public function test_add_tools($request, array $tools, ?TextGenerationOptions $options = null): mixed
            {
                return $this->addTools($request, $tools, $options);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP tools require the optional "prism-php/relay" package.');

        $handler->test_add_tools($request, [new McpServer('github')]);
    }

    public function test_mcp_server_expansion_is_used_for_default_max_steps(): void
    {
        $request = new class
        {
            public array $tools = [];

            public mixed $maxSteps = null;

            public function withTools(array $tools): self
            {
                $this->tools = $tools;

                return $this;
            }

            public function withToolChoice($toolChoice): self
            {
                return $this;
            }

            public function withMaxSteps($maxSteps): self
            {
                $this->maxSteps = $maxSteps;

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

            public function test_add_tools($request, array $tools, ?TextGenerationOptions $options = null): mixed
            {
                return $this->addTools($request, $tools, $options);
            }

            protected function resolveRelayTools(McpServer $server): array
            {
                return [
                    (new PrismToolDefinition)->as('first')->for('first')->using(fn (): string => 'ok'),
                    (new PrismToolDefinition)->as('second')->for('second')->using(fn (): string => 'ok'),
                ];
            }
        };

        $handler->test_add_tools($request, [new McpServer('github')]);

        $this->assertCount(2, $request->tools);
        $this->assertSame(3.0, $request->maxSteps);
    }
}
