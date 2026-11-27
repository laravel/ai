<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasToolMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\PendingToolCall;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function toolMiddlewareAgent(array $middleware, FixedNumberGenerator $tool = new FixedNumberGenerator): Agent
{
    return new class($middleware, $tool) implements Agent, HasToolMiddleware, HasTools
    {
        use Promptable;

        public function __construct(private array $middleware, private FixedNumberGenerator $tool) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [$this->tool];
        }

        public function toolMiddleware(): array
        {
            return $this->middleware;
        }
    };
}

test('tool middleware may let a tool call through', function (): void {
    $seen = [];

    $agent = toolMiddlewareAgent([
        function (PendingToolCall $call, Closure $next) use (&$seen) {
            $seen[] = [$call->name(), $call->arguments()];

            return $next($call);
        },
    ]);

    Ai::fakeAgent($agent::class, [new ToolCall('call_1', 'FixedNumberGenerator', ['size' => 5]), 'Done.']);

    expect($agent->prompt('Generate a number')->toolResults->first()->result)->toBe('72019')
        ->and($seen)->toBe([['FixedNumberGenerator', ['size' => 5]]]);
});

test('tool middleware may refuse a tool call without running the tool', function (): void {
    $agent = toolMiddlewareAgent(
        [fn (PendingToolCall $call, Closure $next) => 'Refused by policy.'],
        new FixedNumberGenerator(throwsException: true),
    );

    Ai::fakeAgent($agent::class, [new ToolCall('call_1', 'FixedNumberGenerator', []), 'Done.']);

    $result = $agent->prompt('Generate a number')->toolResults->first();

    expect($result->result)->toBe('Refused by policy.')
        ->and($result->failed)->toBeFalse();
});

test('tool middleware runs in the order it is declared', function (): void {
    $order = [];

    $agent = toolMiddlewareAgent([
        function (PendingToolCall $call, Closure $next) use (&$order) {
            $order[] = 'first';

            return $next($call);
        },
        function (PendingToolCall $call, Closure $next) use (&$order) {
            $order[] = 'second';

            return $next($call);
        },
    ]);

    Ai::fakeAgent($agent::class, [new ToolCall('call_1', 'FixedNumberGenerator', []), 'Done.']);

    $agent->prompt('Generate a number');

    expect($order)->toBe(['first', 'second']);
});
