<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Tools\Request;

test('tool request arguments can be validated', function (): void {
    $validated = (new Request(['city' => 'Lisbon', 'extra' => 'ignored']))->validate([
        'city' => 'required|string',
    ]);

    expect($validated)->toBe(['city' => 'Lisbon']);
});

test('invalid tool request arguments throw a validation exception', function (): void {
    (new Request(['days' => 'tomorrow']))->validate(['days' => 'required|integer']);
})->throws(ValidationException::class);

test('a validation failure is returned to the model as the tool result', function (): void {
    $invoked = null;
    $failed = 0;

    $events = new Dispatcher;
    $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked = $event;
    });
    $events->listen(ToolFailed::class, function () use (&$failed): void {
        $failed++;
    });

    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments, RunContext $context): string
        {
            return $this->executeTool($tool, $arguments, null, $context);
        }
    };

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Validating tool.';
        }

        public function handle(Request $request): string
        {
            $request->validate(['city' => 'required|string']);

            return 'never reached';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $context = new RunContext(
        'inv_1',
        Mockery::mock(Agent::class),
        Mockery::mock(TextProvider::class),
        'stub-model',
        $events,
    );

    $result = $gateway->invoke($tool, [], $context);

    expect($result)->toBe('The city field is required.')
        ->and($invoked->result)->toBe('The city field is required.')
        ->and($failed)->toBe(0);
});
