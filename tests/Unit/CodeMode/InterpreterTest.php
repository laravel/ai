<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\CodeMode\Catalog;
use Laravel\Ai\CodeMode\Interpreter;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

function dslTool(string $name, Closure $handler, string $description = 'A test tool.'): Tool
{
    return new class($name, $handler, $description) implements Tool
    {
        public function __construct(
            protected string $toolName,
            protected Closure $handler,
            protected string $toolDescription,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return $this->toolDescription;
        }

        public function handle(Request $request): string
        {
            return (string) ($this->handler)($request);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

/** @param array<string, mixed> $program */
function runDsl(array $program, array $tools = [], int|float $timeout = 10, int $maxToolCalls = 25, int $maxOperations = 10000): array
{
    return (new Interpreter(
        new Catalog($tools),
        $timeout,
        $maxToolCalls,
        $maxOperations,
    ))->execute(json_encode($program, JSON_THROW_ON_ERROR));
}

test('the DSL computes with loops conditions and aggregation', function (): void {
    $result = runDsl([
        'steps' => [
            ['set' => 'even', 'value' => []],
            [
                'foreach' => ['items' => [1, 2, 3, 4], 'as' => 'number'],
                'do' => [[
                    'if' => ['$eq' => [['$mod' => [['$var' => 'number'], 2]], 0]],
                    'then' => [['append' => 'even', 'value' => ['$var' => 'number']]],
                ]],
            ],
            ['set' => 'total', 'value' => ['$sum' => ['$var' => 'even']]],
        ],
        'return' => [
            'numbers' => ['$var' => 'even'],
            'total' => ['$var' => 'total'],
            'label' => ['$concat' => ['sum is ', ['$var' => 'total']]],
        ],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toBe(['numbers' => [2, 4], 'total' => 6, 'label' => 'sum is 6']);
});

test('a program orchestrates tools and decodes their JSON results', function (): void {
    $tool = dslTool('lookup', fn (Request $request): string => json_encode([
        'id' => $request['id'],
        'status' => 'open',
    ], JSON_THROW_ON_ERROR));

    $result = runDsl([
        'steps' => [
            ['call' => 'orders.lookup', 'arguments' => ['id' => 'order_42'], 'save' => 'orderJson'],
            ['set' => 'order', 'value' => ['$json' => ['$var' => 'orderJson']]],
        ],
        'return' => [
            'id' => ['$var' => 'order.id'],
            'open' => ['$eq' => [['$var' => 'order.status'], 'open']],
        ],
    ], ['orders.lookup' => $tool]);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toBe(['id' => 'order_42', 'open' => true])
        ->and($result['toolCalls'])->toBe(['orders.lookup']);
});

test('host-language source and unknown expression operators are rejected as data', function (): void {
    $source = (new Interpreter(new Catalog([]), 10, 25, 10000))
        ->execute("return array_map('shell_exec', ['echo pwned']);");
    $operator = runDsl(['return' => ['$php' => 'shell_exec("echo pwned")']]);

    expect($source['ok'])->toBeFalse()
        ->and($source['error']['kind'])->toBe('InvalidProgram')
        ->and($operator['ok'])->toBeFalse()
        ->and($operator['error']['kind'])->toBe('InvalidProgram');
});

test('invalid JSON returned by a tool fails explicitly', function (): void {
    $result = runDsl([
        'steps' => [
            ['call' => 'broken', 'save' => 'payload'],
            ['set' => 'decoded', 'value' => ['$json' => ['$var' => 'payload']]],
        ],
    ], ['broken' => dslTool('broken', fn (): string => '{')]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('InvalidJson');
});

test('tool call IDs are stable children of the provider call ID', function (): void {
    $ids = [];
    $tool = dslTool('echo', function (Request $request) use (&$ids): string {
        $ids[] = $request->toolCallId();

        return 'ok';
    });

    $interpreter = new Interpreter(
        new Catalog(['echo' => $tool]),
        10,
        25,
        10000,
        parentToolCallId: 'provider-call',
    );

    $interpreter->execute(json_encode([
        'steps' => [
            ['call' => 'echo'],
            ['call' => 'echo'],
        ],
    ], JSON_THROW_ON_ERROR));

    expect($ids)->toBe(['provider-call:0', 'provider-call:1']);
});

test('the tool call limit is enforced', function (): void {
    $result = runDsl([
        'steps' => [[
            'foreach' => ['items' => [1, 2, 3], 'as' => 'item'],
            'do' => [['call' => 'echo']],
        ]],
    ], ['echo' => dslTool('echo', fn (): string => 'ok')], maxToolCalls: 2);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ToolCallLimitExceeded')
        ->and($result['toolCalls'])->toHaveCount(2);
});

test('the operation budget bounds nested programs', function (): void {
    $result = runDsl([
        'steps' => [[
            'foreach' => ['items' => range(1, 100), 'as' => 'item'],
            'do' => [['set' => 'copy', 'value' => ['$var' => 'item']]],
        ]],
    ], maxOperations: 20);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('OperationLimitExceeded');
});

test('a tool that returns after the deadline cannot produce a successful program', function (): void {
    $result = runDsl([
        'steps' => [['call' => 'slow', 'save' => 'result']],
        'return' => ['$var' => 'result'],
    ], ['slow' => dslTool('slow', function (): string {
        usleep(20000);

        return 'late';
    })], timeout: 0.001);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('TimeoutExceeded');
});

test('program structure and variable paths fail with pointed diagnostics', function (): void {
    $invalidStep = runDsl(['steps' => [['eval' => 'anything']]]);
    $missingVariable = runDsl(['return' => ['$var' => 'missing.value']]);

    expect($invalidStep['error']['kind'])->toBe('InvalidProgram')
        ->and($missingVariable['error']['kind'])->toBe('UndefinedVariable');
});

test('conditions and arithmetic use strict operand types', function (): void {
    $condition = runDsl(['steps' => [['if' => 1, 'then' => []]]]);
    $arithmetic = runDsl(['return' => ['$add' => ['1', 2]]]);

    expect($condition['error']['kind'])->toBe('TypeError')
        ->and($arithmetic['error']['kind'])->toBe('TypeError');
});

test('hooks observe successful and failed nested calls', function (): void {
    $events = [];
    $interpreter = new Interpreter(
        new Catalog([
            'ok' => dslTool('ok', fn (): string => 'ok'),
            'fail' => dslTool('fail', fn () => throw new RuntimeException('down')),
        ]),
        10,
        25,
        10000,
        onToolCallStart: function (array $call) use (&$events): void {
            $events[] = 'start:'.$call['name'];
        },
        onToolCallEnd: function (array $call) use (&$events): void {
            $events[] = 'end:'.$call['name'].':'.$call['outcome'];
        },
    );

    $interpreter->execute(json_encode([
        'steps' => [
            ['call' => 'ok'],
            ['call' => 'fail'],
        ],
    ], JSON_THROW_ON_ERROR));

    expect($events)->toBe(['start:ok', 'end:ok:success', 'start:fail', 'end:fail:failure']);
});
