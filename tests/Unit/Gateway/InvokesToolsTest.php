<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\RunObservers;
use Laravel\Ai\Tools\Request;

test('each tool invocation reports to the observers it was given', function (): void {
    $events = [];

    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = [], ?RunObservers $observers = null): string
        {
            return $this->executeTool($tool, $arguments, null, $observers);
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

    $observers = function (string $label) use (&$events): RunObservers {
        return new RunObservers(
            invokingTool: function (Tool $tool) use (&$events, $label): void {
                $events[] = $label.' invoking '.$tool->description();
            },
            toolInvoked: function (Tool $tool, array $arguments, mixed $result) use (&$events, $label): void {
                $events[] = $label.' invoked '.$tool->description().':'.$result;
            },
        );
    };

    $nestedTool = $makeTool('nested', fn (): string => 'nested result');

    $delegatingTool = $makeTool('delegating', function () use ($gateway, $nestedTool, $observers): string {
        $gateway->invoke($nestedTool, observers: $observers('sub'));

        return 'delegated result';
    });

    $siblingTool = $makeTool('sibling', fn (): string => 'sibling result');

    $gateway->invoke($delegatingTool, observers: $observers('parent'));
    $gateway->invoke($siblingTool, observers: $observers('parent'));

    expect($events)->toBe([
        'parent invoking delegating',
        'sub invoking nested',
        'sub invoked nested:nested result',
        'parent invoked delegating:delegated result',
        'parent invoking sibling',
        'parent invoked sibling:sibling result',
    ]);
});

test('a tool invocation without observers is silent', function (): void {
    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool): string
        {
            return $this->executeTool($tool, []);
        }
    };

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'unobserved';
        }

        public function handle(Request $request): string
        {
            return 'result';
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    expect($gateway->invoke($tool))->toBe('result');
});
