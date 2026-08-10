<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\CodeMode\CodeMode;
use Laravel\Ai\CodeMode\ExecuteCode;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/** @param array<string, mixed> $program */
function codeModeProgram(array $program): string
{
    return json_encode($program, JSON_THROW_ON_ERROR);
}

test('the agent runs a bounded orchestration program in one invocation', function (): void {
    Event::fake([InvokingTool::class, ToolInvoked::class]);
    CodeModeWeatherTool::$lastToolCallId = null;

    CodeModeAgent::fake([
        new ToolCall('c1', 'execute_code', ['program' => codeModeProgram([
            'steps' => [
                ['call' => 'weather.get_weather', 'arguments' => ['city' => 'Paris'], 'save' => 'weatherJson'],
                ['set' => 'weather', 'value' => ['$json' => ['$var' => 'weatherJson']]],
            ],
            'return' => ['$var' => 'weather.conditions'],
        ])]),
        'The weather in Paris is sunny.',
    ]);

    $response = (new CodeModeAgent)->prompt('What is the weather in Paris?');
    $result = $response->toolResults->firstWhere('name', 'execute_code');
    $decoded = json_decode($result->result, true, flags: JSON_THROW_ON_ERROR);

    expect($response->text)->toBe('The weather in Paris is sunny.')
        ->and($decoded['ok'])->toBeTrue()
        ->and($decoded['value'])->toBe('sunny')
        ->and($decoded['toolCalls'])->toBe(['weather.get_weather'])
        ->and($response->toolCalls->pluck('name')->all())->toBe(['execute_code', 'weather.get_weather'])
        ->and($response->toolResults->pluck('name')->all())->toBe(['execute_code', 'weather.get_weather'])
        ->and(CodeModeWeatherTool::$lastToolCallId)->toBe('c1:0');

    $invoking = Event::dispatched(InvokingTool::class)
        ->first(fn (array $events): bool => $events[0]->tool instanceof CodeModeWeatherTool)[0] ?? null;
    $invoked = Event::dispatched(ToolInvoked::class)
        ->first(fn (array $events): bool => $events[0]->tool instanceof CodeModeWeatherTool)[0] ?? null;

    expect($invoking)->not->toBeNull()
        ->and($invoked)->not->toBeNull()
        ->and($invoking->toolInvocationId)->toBe($invoked->toolInvocationId);
});

test('only execute_code is exposed when the whole catalog fits inline', function (): void {
    $exposed = collect((new CodeModeAgent)->tools())
        ->flatMap(fn (mixed $tool): array => $tool instanceof CodeMode
            ? array_map(fn (Tool $expanded): string => $expanded->name(), $tool->expand(fn ($leaf) => $leaf))
            : [$tool->name()]);

    expect($exposed->all())->toBe(['execute_code']);
});

test('the execute_code description documents the DSL and complete tool schema', function (): void {
    [$executeCode] = CodeMode::for(['weather' => [new CodeModeWeatherTool]])->expand(fn ($leaf) => $leaf);

    expect($executeCode)->toBeInstanceOf(ExecuteCode::class)
        ->and($executeCode->description())
        ->toContain('bounded JSON orchestration program')
        ->toContain('Tool [weather.get_weather]')
        ->toContain('"city"')
        ->toContain('"description":"The city name."')
        ->toContain('There are no host-language callbacks');
});

test('execution limits and serializable hooks flow into programs', function (): void {
    $calls = [];

    $mode = CodeMode::for([new CodeModeWeatherTool])
        ->maxToolCalls(1)
        ->onToolCallEnd(function (array $call) use (&$calls): void {
            $calls[] = $call['name'];
        });

    expect(fn (): string => serialize($mode))->not->toThrow(Throwable::class);

    [$executeCode] = $mode->expand(fn ($leaf) => $leaf);
    $result = json_decode($executeCode->handle(new Request([
        'program' => codeModeProgram(['steps' => [
            ['call' => 'get_weather', 'arguments' => ['city' => 'Paris']],
            ['call' => 'get_weather', 'arguments' => ['city' => 'Lyon']],
        ]]),
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ToolCallLimitExceeded')
        ->and($calls)->toBe(['get_weather']);
});

test('oversized and invalid UTF-8 results always produce valid JSON envelopes', function (): void {
    [$executeCode] = CodeMode::for([new CodeModeInvalidUtf8Tool])
        ->maxOutputBytes(256)
        ->expand(fn ($leaf) => $leaf);

    $oversized = $executeCode->handle(new Request([
        'program' => codeModeProgram(['return' => str_repeat('x', 5000)]),
    ]));
    $invalidUtf8 = $executeCode->handle(new Request([
        'program' => codeModeProgram([
            'steps' => [['call' => 'invalid_utf8', 'save' => 'value']],
            'return' => ['$var' => 'value'],
        ]),
    ]));

    expect(strlen($oversized))->toBeLessThanOrEqual(256)
        ->and(json_decode($oversized, true, flags: JSON_THROW_ON_ERROR)['truncated'])->toBeTrue()
        ->and(json_decode($invalidUtf8, true, flags: JSON_THROW_ON_ERROR)['value'])->toContain('1');
});

test('impossible output budgets and operation limits fail during configuration', function (): void {
    expect(fn () => CodeMode::for([])->maxOutputBytes(255))
        ->toThrow(InvalidArgumentException::class, 'at least 256')
        ->and(fn () => CodeMode::for([])->maxOperations(0))
        ->toThrow(InvalidArgumentException::class, 'at least one');
});

test('approval-gated and invalid catalog entries fail before reaching a provider', function (): void {
    expect(fn () => CodeMode::for([new CodeModeDeleteFileTool]))
        ->toThrow(InvalidArgumentException::class, 'Tool approvals are not supported')
        ->and(fn () => CodeMode::for([new CodeModeWeatherTool, new CodeModeWeatherTool])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Multiple tools resolve to the path [get_weather]')
        ->and(fn () => CodeMode::for(['strings are not tools'])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Only tools may be placed in a code mode tree')
        ->and(fn () => CodeMode::for(['bad namespace!' => [new CodeModeWeatherTool]])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'namespace');
});

test('two code mode wrappers fail with an actionable provider-name collision', function (): void {
    DuplicateCodeModeAgent::fake(['unused']);

    expect(fn () => (new DuplicateCodeModeAgent)->prompt('Run both catalogs.'))
        ->toThrow(InvalidArgumentException::class, 'provider name [execute_code]');
});

test('large catalogs use bounded descriptions and structured search results', function (): void {
    $tools = array_map(fn (int $i): Tool => new CodeModeFillerTool($i), range(1, 200));
    $expanded = CodeMode::for($tools)->expand(fn ($leaf) => $leaf);

    expect(array_map(fn (Tool $tool): string => $tool->name(), $expanded))
        ->toBe(['execute_code', 'search_tools']);

    [$executeCode, $searchTools] = $expanded;
    $description = $executeCode->description();
    $found = json_decode(
        $searchTools->handle(new Request(['query' => 'filler_tool_200', 'limit' => 999])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(strlen($description))->toBeLessThan(12000)
        ->and($description)->toContain('additional tools are available through search_tools')
        ->and($description)->not->toContain('filler_tool_200')
        ->and($found)->toHaveCount(50)
        ->and($found[0]['path'])->toBe('filler_tool_200')
        ->and($found[0]['description'])->toContain('Filler tool number 200')
        ->and($found[0]['schema'])->toBeArray();
});

class CodeModeWeatherTool implements Tool
{
    public static ?string $lastToolCallId = null;

    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get the current weather for a city.';
    }

    public function handle(Request $request): string
    {
        static::$lastToolCallId = $request->toolCallId();

        return json_encode(['city' => $request['city'], 'conditions' => 'sunny'], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->description('The city name.')->required()];
    }
}

class CodeModeDeleteFileTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function name(): string
    {
        return 'delete_file';
    }

    public function description(): string
    {
        return 'Delete a file from disk.';
    }

    public function handle(Request $request): string
    {
        return 'deleted';
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Deletes a tracked file');
    }

    public function schema(JsonSchema $schema): array
    {
        return ['path' => $schema->string()->required()];
    }
}

class CodeModeInvalidUtf8Tool implements Tool
{
    public function name(): string
    {
        return 'invalid_utf8';
    }

    public function description(): string
    {
        return 'Return invalid UTF-8 bytes.';
    }

    public function handle(Request $request): string
    {
        return "\xB1\x31";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class CodeModeAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [CodeMode::for(['weather' => [new CodeModeWeatherTool]])];
    }
}

class DuplicateCodeModeAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            CodeMode::for(['weather' => [new CodeModeWeatherTool]]),
            CodeMode::for(['other' => [new CodeModeWeatherTool]]),
        ];
    }
}

class CodeModeFillerTool implements Tool
{
    public function __construct(protected int $index) {}

    public function name(): string
    {
        return 'filler_tool_'.$this->index;
    }

    public function description(): string
    {
        return 'Filler tool number '.$this->index.' used to grow the catalog past the inline budget.';
    }

    public function handle(Request $request): string
    {
        return (string) $this->index;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
