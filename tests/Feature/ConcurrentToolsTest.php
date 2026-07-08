<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Concurrency;
use Laravel\Ai\Attributes\Concurrent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

#[Concurrent]
class ConcurrentEchoTool implements Tool
{
    public function __construct(public string $label, public ?string $throws = null) {}

    public function name(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->label;
    }

    public function handle(Request $request): string
    {
        if ($this->throws !== null) {
            throw new RuntimeException($this->throws);
        }

        return "result:{$this->label}";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class SequentialEchoTool extends ConcurrentEchoTool {}

function concurrentToolLoop(): object
{
    return new class(Mockery::mock(StepTextGateway::class)) extends TextGenerationLoop
    {
        /**
         * @param  ToolCall[]  $toolCalls
         * @param  Tool[]  $tools
         */
        public function runToolCalls(array $toolCalls, array $tools): array
        {
            return $this->executeToolCalls($toolCalls, $tools);
        }
    };
}

/**
 * @param  Tool[]  $tools
 * @return ToolCall[]
 */
function toolCallsFor(array $tools): array
{
    return array_map(function (Tool $tool, int $i) {
        $name = ToolNameResolver::resolve($tool);

        return new ToolCall("call-{$i}", $name, [], "call-{$i}");
    }, $tools, array_keys($tools));
}

beforeEach(fn () => config(['concurrency.default' => 'sync']));

test('it runs a batch of concurrent tools through the concurrency driver', function () {
    $tools = [new ConcurrentEchoTool('a'), new ConcurrentEchoTool('b'), new ConcurrentEchoTool('c')];

    $results = concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);

    expect(array_map(fn ($r) => $r->result, $results))->toBe(['result:a', 'result:b', 'result:c']);
});

test('it runs non-concurrent tools inline without the driver', function () {
    Concurrency::shouldReceive('run')->never();

    $tools = [new SequentialEchoTool('x'), new SequentialEchoTool('y')];

    $results = concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);

    expect(array_map(fn ($r) => $r->result, $results))->toBe(['result:x', 'result:y']);
});

test('it runs a lone concurrent tool inline instead of the driver', function () {
    Concurrency::shouldReceive('run')->never();

    $tools = [new ConcurrentEchoTool('solo')];

    $results = concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);

    expect($results[0]->result)->toBe('result:solo');
});

test('it preserves order around a non-concurrent tool between concurrent ones', function () {
    $tools = [new ConcurrentEchoTool('a'), new SequentialEchoTool('mid'), new ConcurrentEchoTool('b')];

    $results = concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);

    expect(array_map(fn ($r) => $r->result, $results))->toBe(['result:a', 'result:mid', 'result:b']);
});

test('it falls back to inline execution when the driver fails', function () {
    Concurrency::shouldReceive('run')->once()->andThrow(new RuntimeException('driver unavailable'));

    $tools = [new ConcurrentEchoTool('a'), new ConcurrentEchoTool('b')];

    $results = concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);

    expect(array_map(fn ($r) => $r->result, $results))->toBe(['result:a', 'result:b']);
});

test('it propagates a tool exception raised inside a concurrent batch', function () {
    $tools = [new ConcurrentEchoTool('a'), new ConcurrentEchoTool('b', throws: 'kaboom')];

    concurrentToolLoop()->runToolCalls(toolCallsFor($tools), $tools);
})->throws(RuntimeException::class, 'kaboom');
