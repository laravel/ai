<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Tools\Request;

test('tool invocation callbacks are restored after nested tool invocations', function (): void {
    $events = [];

    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = []): string
        {
            return $this->executeTool($tool, $arguments);
        }
    };

    $makeTool = fn (string $name, Closure $handler): Tool => new class($name, $handler) implements Tool
    {
        public function __construct(
            protected string $name,
            protected Closure $handler,
        ) {}

        public function description(): string
        {
            return $this->name;
        }

        public function handle(Request $request): string
        {
            return call_user_func($this->handler, $request);
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $gateway->onToolInvocation(
        invoking: function (Tool $tool) use (&$events): void {
            $events[] = 'parent invoking '.($tool->description());
        },
        invoked: function (Tool $tool, array $arguments, mixed $result) use (&$events): void {
            $events[] = 'parent invoked '.($tool->description()).':'.$result;
        },
    );

    $nestedTool = $makeTool('nested', fn (): string => 'nested result');

    $delegatingTool = $makeTool('delegating', function () use ($gateway, $nestedTool, &$events): string {
        $gateway->onToolInvocation(
            invoking: function (Tool $tool) use (&$events): void {
                $events[] = 'sub invoking '.($tool->description());
            },
            invoked: function (Tool $tool, array $arguments, mixed $result) use (&$events): void {
                $events[] = 'sub invoked '.($tool->description()).':'.$result;
            },
        );

        $gateway->invoke($nestedTool);

        return 'delegated result';
    });

    $siblingTool = $makeTool('sibling', fn (): string => 'sibling result');

    $gateway->invoke($delegatingTool);
    $gateway->invoke($siblingTool);

    expect($events)->toBe([
        'parent invoking delegating',
        'sub invoking nested',
        'sub invoked nested:nested result',
        'parent invoked delegating:delegated result',
        'parent invoking sibling',
        'parent invoked sibling:sibling result',
    ]);
});

test('invocation callbacks keep per-call context and forward tool call IDs', function (): void {
    $events = [];
    $receivedId = null;

    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = [], ?string $toolCallId = null): string
        {
            return $this->executeTool($tool, $arguments, $toolCallId);
        }
    };

    $tool = new class($receivedId) implements Tool
    {
        protected mixed $receivedId;

        public function __construct(mixed &$receivedId)
        {
            $this->receivedId = &$receivedId;
        }

        public function description(): string
        {
            return 'A context test tool.';
        }

        public function handle(Request $request): string
        {
            $this->receivedId = $request->toolCallId();

            return 'result';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $gateway->onToolInvocation(
        invoking: function () use (&$events): string {
            $events[] = 'invoking';

            return 'correlation-id';
        },
        invoked: function (Tool $tool, array $arguments, mixed $result, string $context) use (&$events): void {
            $events[] = $context.':'.$result;
        },
    );

    $gateway->invoke($tool, toolCallId: 'provider-id');

    expect($receivedId)->toBe('provider-id')
        ->and($events)->toBe(['invoking', 'correlation-id:result']);
});
