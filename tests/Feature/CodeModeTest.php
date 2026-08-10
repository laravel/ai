<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\CodeMode\CodeMode;
use Laravel\Ai\CodeMode\ExecuteCode;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

test('the agent runs a program that orchestrates catalog tools in one invocation', function (): void {
    CodeModeAgent::fake([
        new ToolCall('c1', 'execute_code', ['code' => <<<'PHP'
            $weather = json_decode(tool('weather.get_weather', ['city' => 'Paris']));

            return strtoupper($weather['conditions']);
        PHP]),
        'The weather in Paris is sunny.',
    ]);

    $response = (new CodeModeAgent)->prompt('What is the weather in Paris?');

    $result = $response->toolResults->firstWhere('name', 'execute_code');
    $decoded = json_decode($result->result, true);

    expect($response->text)->toBe('The weather in Paris is sunny.')
        ->and($decoded['ok'])->toBeTrue()
        ->and($decoded['value'])->toBe('SUNNY')
        ->and($decoded['toolCalls'])->toBe(['weather.get_weather']);
});

test('only the execute_code tool is exposed when the whole catalog fits inline', function (): void {
    $exposed = collect((new CodeModeAgent)->tools())
        ->flatMap(fn (mixed $tool): array => $tool instanceof CodeMode
            ? array_map(fn (Tool $expanded): string => $expanded->name(), $tool->expand(fn ($leaf) => $leaf))
            : [$tool->name()]);

    expect($exposed->all())->toBe(['execute_code']);
});

test('the execute_code description carries the tool catalog and signatures', function (): void {
    [$executeCode] = CodeMode::for(['weather' => [new CodeModeWeatherTool]])->expand(fn ($leaf) => $leaf);

    expect($executeCode)->toBeInstanceOf(ExecuteCode::class)
        ->and($executeCode->description())
        ->toContain("tool('weather.get_weather', ['city' => string /* The city name. */]): string")
        ->toContain('Get the current weather for a city.');
});

test('execution limits and hooks flow from the wrapper into programs', function (): void {
    $calls = [];

    [$executeCode] = CodeMode::for([new CodeModeWeatherTool])
        ->maxToolCalls(1)
        ->onToolCallEnd(function (array $call) use (&$calls): void {
            $calls[] = $call['name'];
        })
        ->expand(fn ($leaf) => $leaf);

    $result = json_decode($executeCode->handle(new Request([
        'code' => 'tool(\'get_weather\', [\'city\' => \'Paris\']); tool(\'get_weather\', [\'city\' => \'Lyon\']);',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['kind'])->toBe('ToolCallLimitExceeded')
        ->and($calls)->toBe(['get_weather']);
});

test('oversized results are truncated to the output byte budget', function (): void {
    [$executeCode] = CodeMode::for([new CodeModeWeatherTool])
        ->maxOutputBytes(200)
        ->expand(fn ($leaf) => $leaf);

    $output = $executeCode->handle(new Request(['code' => 'return str_repeat(\'x\', 5000);']));

    expect(strlen($output))->toBeLessThanOrEqual(200)
        ->and($output)->toContain('truncated');
});

test('an approval-gated tool in the tree fails loudly instead of bypassing the gate', function (): void {
    expect(fn () => CodeMode::for([new CodeModeDeleteFileTool])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Tool approvals are not supported');
});

test('two tools resolving to the same path fail loudly', function (): void {
    expect(fn () => CodeMode::for([new CodeModeWeatherTool, new CodeModeWeatherTool])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Multiple tools resolve to the path [get_weather]');
});

test('a non-tool entry in the tree fails loudly', function (): void {
    expect(fn () => CodeMode::for(['strings are not tools'])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Only tools may be placed in a code mode tree');
});

test('an invalid namespace fails loudly', function (): void {
    expect(fn () => CodeMode::for(['bad namespace!' => [new CodeModeWeatherTool]])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'namespace');
});

test('a program can discover a catalog tool through search_tools', function (): void {
    [$executeCode] = CodeMode::for(['weather' => [new CodeModeWeatherTool]])->expand(fn ($leaf) => $leaf);

    $result = json_decode($executeCode->handle(new Request([
        'code' => "return search_tools('weather');",
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'][0]['path'])->toBe('weather.get_weather')
        ->and($result['value'][0]['signature'])->toContain("tool('weather.get_weather', ['city' => string");
});

test('a catalog too large to inline is deferred to the search_tools tool', function (): void {
    $tools = array_map(fn (int $i): Tool => new CodeModeFillerTool($i), range(1, 200));

    $expanded = CodeMode::for($tools)->expand(fn ($leaf) => $leaf);

    expect(array_map(fn (Tool $tool): string => $tool->name(), $expanded))
        ->toBe(['execute_code', 'search_tools']);

    [$executeCode, $searchTools] = $expanded;

    $description = $executeCode->description();

    expect($description)->toContain("tool('filler_tool_1',")
        ->and($description)->toContain('look their signatures up with the search_tools tool')
        ->and($description)->toContain('filler_tool_200')
        ->and($description)->not->toContain("tool('filler_tool_200'");

    $found = json_decode($searchTools->handle(new Request(['query' => 'filler_tool_200', 'limit' => 1])), true);

    expect($found[0]['path'])->toBe('filler_tool_200')
        ->and($found[0]['signature'])->toContain('Filler tool number 200');
});

class CodeModeWeatherTool implements Tool
{
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
        return json_encode(['city' => $request['city'], 'conditions' => 'sunny']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('The city name.')->required(),
        ];
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

class CodeModeAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            CodeMode::for([
                'weather' => [new CodeModeWeatherTool],
            ]),
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
