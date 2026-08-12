<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Tools\Request;

function toolInvokingGateway(): object
{
    return new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = [], ?RunContext $context = null): string
        {
            return $this->executeTool($tool, $arguments, null, $context);
        }
    };
}

function stubTool(string $name, Closure $handler): Tool
{
    return new class($name, $handler) implements Tool
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
}

function stubRunContext(Dispatcher $events, string $invocationId = 'inv_1'): RunContext
{
    return new RunContext(
        $invocationId,
        Mockery::mock(Agent::class),
        Mockery::mock(TextProvider::class),
        'stub-model',
        $events,
    );
}

test('each tool invocation reports against the context it was given', function (): void {
    $seen = [];

    $context = function (string $label) use (&$seen): RunContext {
        $events = new Dispatcher;

        $events->listen(InvokingTool::class, function (InvokingTool $event) use (&$seen, $label): void {
            $seen[] = $label.' invoking '.$event->tool->description();
        });

        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen, $label): void {
            $seen[] = $label.' invoked '.$event->tool->description().':'.$event->result;
        });

        return stubRunContext($events);
    };

    $gateway = toolInvokingGateway();

    $nestedTool = stubTool('nested', fn (): string => 'nested result');

    $delegatingTool = stubTool('delegating', function () use ($gateway, $nestedTool, $context): string {
        $gateway->invoke($nestedTool, context: $context('sub'));

        return 'delegated result';
    });

    $siblingTool = stubTool('sibling', fn (): string => 'sibling result');

    $gateway->invoke($delegatingTool, context: $context('parent'));
    $gateway->invoke($siblingTool, context: $context('parent'));

    expect($seen)->toBe([
        'parent invoking delegating',
        'sub invoking nested',
        'sub invoked nested:nested result',
        'parent invoked delegating:delegated result',
        'parent invoking sibling',
        'parent invoked sibling:sibling result',
    ]);
});

test('the invoking and invoked events share a tool invocation id', function (): void {
    $ids = [];

    $events = new Dispatcher;
    $events->listen(InvokingTool::class, function (InvokingTool $event) use (&$ids): void {
        $ids[] = $event->toolInvocationId;
    });
    $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$ids): void {
        $ids[] = $event->toolInvocationId;
    });

    toolInvokingGateway()->invoke(stubTool('paired', fn (): string => 'result'), context: stubRunContext($events));

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toBe($ids[1]);
});

test('a tool invocation without a run context is silent', function (): void {
    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool): string
        {
            return $this->executeTool($tool, []);
        }
    };

    expect($gateway->invoke(stubTool('unobserved', fn (): string => 'result')))->toBe('result');
});
