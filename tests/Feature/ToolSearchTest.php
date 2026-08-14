<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\ExecuteTools;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SearchTools;
use Laravel\Ai\Tools\ToolSearch;

test('the agent executes searched catalog tools in one invocation', function (): void {
    Event::fake([InvokingTool::class, ToolInvoked::class]);
    ToolSearchWeatherTool::$lastToolCallId = null;

    ToolSearchAgent::fake([
        new ToolCall('c1', 'execute_tools', ['calls' => [
            ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
        ]]),
        'The weather in Paris is sunny.',
    ]);

    $response = (new ToolSearchAgent)->prompt('What is the weather in Paris?');
    $result = $response->toolResults->firstWhere('name', 'execute_tools');
    $decoded = json_decode($result->result, true, flags: JSON_THROW_ON_ERROR);

    expect($response->text)->toBe('The weather in Paris is sunny.')
        ->and($decoded['ok'])->toBeTrue()
        ->and($decoded['results'][0]['name'])->toBe('get_weather')
        ->and(json_decode($decoded['results'][0]['result'], true)['conditions'])->toBe('sunny')
        ->and($response->toolCalls->pluck('name')->all())->toBe(['execute_tools', 'get_weather'])
        ->and($response->toolResults->pluck('name')->all())->toBe(['execute_tools', 'get_weather'])
        ->and(ToolSearchWeatherTool::$lastToolCallId)->toBe('c1:0');

    $invoking = Event::dispatched(InvokingTool::class)
        ->first(fn (array $events): bool => $events[0]->tool instanceof ToolSearchWeatherTool)[0] ?? null;
    $invoked = Event::dispatched(ToolInvoked::class)
        ->first(fn (array $events): bool => $events[0]->tool instanceof ToolSearchWeatherTool)[0] ?? null;

    expect($invoking)->not->toBeNull()
        ->and($invoked)->not->toBeNull()
        ->and($invoking->toolInvocationId)->toBe($invoked->toolInvocationId);
});

test('a tool search expands into search_tools and execute_tools only', function (): void {
    $expanded = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($leaf) => $leaf);

    expect(array_map(fn (Tool $tool): string => $tool->name(), $expanded))
        ->toBe(['search_tools', 'execute_tools'])
        ->and($expanded[0])->toBeInstanceOf(SearchTools::class)
        ->and($expanded[1])->toBeInstanceOf(ExecuteTools::class);
});

test('search_tools returns names, descriptions, and complete schemas', function (): void {
    [$searchTools] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($leaf) => $leaf);

    $found = json_decode($searchTools->handle(new Request(['query' => 'weather city'])), true, flags: JSON_THROW_ON_ERROR);
    $browsed = json_decode($searchTools->handle(new Request([])), true, flags: JSON_THROW_ON_ERROR);

    expect($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('get_weather')
        ->and($found[0]['description'])->toContain('current weather')
        ->and($found[0]['schema']['properties']['city']['description'])->toBe('The city name.')
        ->and($browsed)->toHaveCount(1);
});

test('grouped catalogs surface group names in search results and the search_tools description', function (): void {
    [$searchTools, $executeTools] = ToolSearch::for([
        'github' => [new ToolSearchWeatherTool],
        new ToolSearchFillerTool(1),
    ])->expand(fn ($leaf) => $leaf);

    $found = json_decode($searchTools->handle(new Request(['query' => 'github'])), true, flags: JSON_THROW_ON_ERROR);
    $result = json_decode($executeTools->handle(new Request(['calls' => [
        ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
    ]])), true, flags: JSON_THROW_ON_ERROR);

    expect($searchTools->description())->toContain('The catalog covers: github.')
        ->and($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('get_weather')
        ->and($found[0]['group'])->toBe('github')
        ->and($result['ok'])->toBeTrue();
});

test('execute_tools runs calls in order and stops on the first error', function (): void {
    ToolSearchWeatherTool::$lastToolCallId = null;

    [, $executeTools] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($leaf) => $leaf);

    $result = json_decode($executeTools->handle(new Request(['calls' => [
        ['name' => 'unknown_tool'],
        ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
    ]])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ok'])->toBeFalse()
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['error'])->toContain('Unknown tool [unknown_tool]')
        ->and(ToolSearchWeatherTool::$lastToolCallId)->toBeNull();
});

test('execute_tools captures tool exceptions as error results', function (): void {
    [, $executeTools] = ToolSearch::for([new ToolSearchFailingTool, new ToolSearchWeatherTool])->expand(fn ($leaf) => $leaf);

    $result = json_decode($executeTools->handle(new Request(['calls' => [
        ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
        ['name' => 'always_fails'],
        ['name' => 'get_weather', 'arguments' => ['city' => 'Lyon']],
    ]])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ok'])->toBeFalse()
        ->and($result['results'])->toHaveCount(2)
        ->and($result['results'][1]['error'])->toContain('Tool [always_fails] failed: boom');
});

test('execute_tools enforces the call and output limits', function (): void {
    [, $executeTools] = ToolSearch::for([new ToolSearchWeatherTool])
        ->maxToolCalls(1)
        ->expand(fn ($leaf) => $leaf);

    $callLimit = json_decode($executeTools->handle(new Request(['calls' => [
        ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
        ['name' => 'get_weather', 'arguments' => ['city' => 'Lyon']],
    ]])), true, flags: JSON_THROW_ON_ERROR);

    [, $executeTools] = ToolSearch::for([new ToolSearchWeatherTool])
        ->maxOutputBytes(256)
        ->expand(fn ($leaf) => $leaf);

    $outputLimit = json_decode($executeTools->handle(new Request(['calls' => [
        ['name' => 'get_weather', 'arguments' => ['city' => str_repeat('x', 500)]],
    ]])), true, flags: JSON_THROW_ON_ERROR);

    expect($callLimit['ok'])->toBeFalse()
        ->and($callLimit['error']['kind'])->toBe('ToolCallLimitExceeded')
        ->and($outputLimit['error'])->toBe([
            'kind' => 'OutputLimitExceeded',
            'message' => 'The tool output exceeded 256 bytes.',
        ])
        ->and($outputLimit['completedToolCalls'])->toBe(1)
        ->and($outputLimit['attemptedToolCalls'])->toBe(1);
});

test('invalid UTF-8 results still produce valid JSON envelopes', function (): void {
    [, $executeTools] = ToolSearch::for([new ToolSearchInvalidUtf8Tool])->expand(fn ($leaf) => $leaf);

    $result = $executeTools->handle(new Request(['calls' => [['name' => 'invalid_utf8']]]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR)['results'][0]['result'])->toContain('1');
});

test('impossible limits fail during configuration', function (): void {
    expect(fn () => ToolSearch::for([])->maxOutputBytes(255))
        ->toThrow(InvalidArgumentException::class, 'at least 256')
        ->and(fn () => ToolSearch::for([])->maxToolCalls(0))
        ->toThrow(InvalidArgumentException::class, 'at least one');
});

test('approval-gated and invalid catalog entries fail before reaching a provider', function (): void {
    expect(fn () => ToolSearch::for([new ToolSearchDeleteFileTool]))
        ->toThrow(InvalidArgumentException::class, 'Tool approvals are not supported')
        ->and(fn () => ToolSearch::for([new ToolSearchWeatherTool, new ToolSearchWeatherTool])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Multiple tools resolve to the name [get_weather]')
        ->and(fn () => ToolSearch::for(['strings are not tools'])->expand(fn ($leaf) => $leaf))
        ->toThrow(InvalidArgumentException::class, 'Only tools may be placed in a tool search catalog');
});

test('two tool search wrappers fail with an actionable provider-name collision', function (): void {
    DuplicateToolSearchAgent::fake(['unused']);

    expect(fn () => (new DuplicateToolSearchAgent)->prompt('Run both catalogs.'))
        ->toThrow(InvalidArgumentException::class, 'provider name [search_tools]');
});

test('large catalogs return bounded structured search results', function (): void {
    $tools = array_map(fn (int $i): Tool => new ToolSearchFillerTool($i), range(1, 200));
    [$searchTools] = ToolSearch::for($tools)->expand(fn ($leaf) => $leaf);

    $found = json_decode(
        $searchTools->handle(new Request(['query' => 'filler_tool_200', 'limit' => 999])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($found)->toHaveCount(50)
        ->and($found[0]['name'])->toBe('filler_tool_200')
        ->and($found[0]['description'])->toContain('Filler tool number 200')
        ->and($found[0]['schema'])->toBeArray();
});

class ToolSearchWeatherTool implements Tool
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

class ToolSearchFailingTool implements Tool
{
    public function name(): string
    {
        return 'always_fails';
    }

    public function description(): string
    {
        return 'Always throw an exception.';
    }

    public function handle(Request $request): string
    {
        throw new RuntimeException('boom');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class ToolSearchDeleteFileTool implements Approvable, Tool
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

class ToolSearchInvalidUtf8Tool implements Tool
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

class ToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [ToolSearch::for([new ToolSearchWeatherTool])];
    }
}

class DuplicateToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            ToolSearch::for([new ToolSearchWeatherTool]),
            ToolSearch::for([new ToolSearchFillerTool(1)]),
        ];
    }
}

class ToolSearchFillerTool implements Tool
{
    public function __construct(protected int $index) {}

    public function name(): string
    {
        return 'filler_tool_'.$this->index;
    }

    public function description(): string
    {
        return 'Filler tool number '.$this->index.' used to grow the catalog.';
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
