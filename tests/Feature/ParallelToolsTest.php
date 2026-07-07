<?php

use Carbon\CarbonInterval;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Concurrency;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Concerns\CanBeConcurrent;
use Laravel\Ai\Tools\Request;
use Laravel\SerializableClosure\SerializableClosure;

test('a tool can opt into concurrent execution', function () {
    $tool = new SequentialRecordingTool('bravo', new ArrayObject);

    expect($tool->isConcurrent())->toBeFalse()
        ->and($tool->concurrent()->isConcurrent())->toBeTrue();
});

test('parallel tools run together with results in original order and correctly paired events', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B'),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new TextGenerationLoop($gateway);

    $invoking = [];
    $invoked = [];

    $loop->onToolInvocation(
        invoking: function (Tool $tool, array $arguments, string $id) use (&$invoking) {
            $invoking[$tool->name()] = $id;
        },
        invoked: function (Tool $tool, array $arguments, mixed $result, string $id) use (&$invoked) {
            $invoked[$tool->name()] = $id;
        },
    );

    $response = $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    $results = collect($response->steps->first()->toolResults)->map->result->all();

    expect($results)->toBe(['A', 'B'])
        ->and($log->getArrayCopy())->toBe(['alpha', 'bravo'])
        ->and($invoking)->toHaveKeys(['alpha', 'bravo'])
        ->and($invoked['alpha'])->toBe($invoking['alpha'])
        ->and($invoked['bravo'])->toBe($invoking['bravo'])
        ->and($invoking['alpha'])->not->toBe($invoking['bravo']);
});

test('sequential tools are barriers between consecutive concurrent batches', function () {
    Concurrency::shouldReceive('run')
        ->twice()
        ->andReturnUsing(fn (array $tasks) => array_map(fn (Closure $task) => $task(), $tasks));

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('first', $log, '1'),
        new ParallelRecordingTool('second', $log, '2'),
        new SequentialRecordingTool('barrier', $log, 'B'),
        new ParallelRecordingTool('third', $log, '3'),
        new ParallelRecordingTool('fourth', $log, '4'),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-1', 'first', [], 'call-1'),
            new ToolCall('call-2', 'second', [], 'call-2'),
            new ToolCall('call-b', 'barrier', [], 'call-b'),
            new ToolCall('call-3', 'third', [], 'call-3'),
            new ToolCall('call-4', 'fourth', [], 'call-4'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    expect(collect($response->steps->first()->toolResults)->map->result->all())
        ->toBe(['1', '2', 'B', '3', '4'])
        ->and($log->getArrayCopy())->toBe(['first', 'second', 'barrier', 'third', 'fourth']);
});

test('a single tool call does not route through the concurrency driver', function () {
    Concurrency::shouldReceive('run')->never();

    $log = new ArrayObject;
    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-1', 'solo', [], 'call-1'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        [new ParallelRecordingTool('solo', $log, 'S')],
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    expect(collect($response->steps->first()->toolResults)->map->result->all())->toBe(['S']);
});

test('tools run sequentially when the concurrency driver cannot run in the current environment', function () {
    Concurrency::shouldReceive('run')->never();

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B'),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new class($gateway) extends TextGenerationLoop
    {
        protected function canRunInParallel(): bool
        {
            return false;
        }
    };

    $response = $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    expect(collect($response->steps->first()->toolResults)->map->result->all())->toBe(['A', 'B'])
        ->and($log->getArrayCopy())->toBe(['alpha', 'bravo']);
});

test('an exception in a parallel tool bubbles up and fails the step', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B', throws: true),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    ))->toThrow(RuntimeException::class, 'boom:bravo');
});

test('a failing parallel tool still delivers invoked events to its successful siblings', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B', throws: true),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new TextGenerationLoop($gateway);

    $invoking = [];
    $invoked = [];
    $failed = [];

    $loop->onToolInvocation(
        invoking: function (Tool $tool, array $arguments, string $id) use (&$invoking) {
            $invoking[$tool->name()] = $id;
        },
        invoked: function (Tool $tool, array $arguments, mixed $result, string $id) use (&$invoked) {
            $invoked[$tool->name()] = $id;
        },
        failed: function (Tool $tool, array $arguments, Throwable $e, string $id) use (&$failed) {
            $failed[$tool->name()] = $id;
        },
    );

    expect(fn () => $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    ))->toThrow(RuntimeException::class, 'boom:bravo');

    // The successful sibling is still reported while the failed tool's invocation is closed out rather than left dangling.
    expect($invoked)->toHaveKey('alpha')
        ->and($invoked)->not->toHaveKey('bravo')
        ->and($invoked['alpha'])->toBe($invoking['alpha'])
        ->and($failed)->toHaveKey('bravo')
        ->and($failed)->not->toHaveKey('alpha')
        ->and($failed['bravo'])->toBe($invoking['bravo']);
});

test('a failing sequential tool fires the failed callback with its invocation id', function () {
    $log = new ArrayObject;

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-1', 'solo', [], 'call-1'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new TextGenerationLoop($gateway);

    $invoking = [];
    $failed = [];

    $loop->onToolInvocation(
        invoking: function (Tool $tool, array $arguments, string $id) use (&$invoking) {
            $invoking[$tool->name()] = $id;
        },
        invoked: fn () => null,
        failed: function (Tool $tool, array $arguments, Throwable $e, string $id) use (&$failed) {
            $failed[$tool->name()] = $id;
        },
    );

    expect(fn () => $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        [new ParallelRecordingTool('solo', $log, 'S', throws: true)],
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new SequentialAgent),
        null,
    ))->toThrow(RuntimeException::class, 'boom:solo');

    expect($failed)->toHaveKey('solo')
        ->and($failed['solo'])->toBe($invoking['solo']);
});

test('a parallel batch degrades to inline execution when the concurrency driver cannot run', function () {
    Concurrency::extend('throwing', fn () => new ThrowingConcurrencyDriver);
    config(['concurrency.default' => 'throwing']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B'),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new TextGenerationLoop($gateway);

    $invoked = [];

    $loop->onToolInvocation(
        invoking: fn () => null,
        invoked: function (Tool $tool, array $arguments, mixed $result, string $id) use (&$invoked) {
            $invoked[] = $tool->name();
        },
    );

    $response = $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    // The driver threw, so the batch ran inline instead: each tool executed exactly once and produced its result in order.
    expect(collect($response->steps->first()->toolResults)->map->result->all())->toBe(['A', 'B'])
        ->and($log->getArrayCopy())->toBe(['alpha', 'bravo'])
        ->and($invoked)->toBe(['alpha', 'bravo']);
});

test('an unknown tool in a parallel batch throws before any tool executes', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-x', 'ghost', [], 'call-x'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        [new ParallelRecordingTool('alpha', $log, 'A')],
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    ))->toThrow(NoSuchToolException::class)
        ->and($log->getArrayCopy())->toBe([]);
});

test('streamed parallel tools yield one result per tool in original order', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B'),
    ];

    $callA = new ToolCall('call-a', 'alpha', [], 'call-a');
    $callB = new ToolCall('call-b', 'bravo', [], 'call-b');

    $gateway = parallelToolsGateway(streams: [
        [[new ToolCallEvent('tc-a', $callA, time()), new ToolCallEvent('tc-b', $callB, time())],
            new StepResponse('', [$callA, $callB], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1')],
        [[], new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model'))],
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'inv-1',
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    ));

    $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class)->values();

    expect($toolResults)->toHaveCount(2)
        ->and($toolResults[0]->toolResult->result)->toBe('A')
        ->and($toolResults[1]->toolResult->result)->toBe('B')
        ->and($log->getArrayCopy())->toBe(['alpha', 'bravo']);
});

test('parallel tools survive serialization and marshal results back in order under a process-style driver', function () {
    Concurrency::extend('serializing', fn () => new SerializingConcurrencyDriver);
    config(['concurrency.default' => 'serializing']);

    $log = new ArrayObject;
    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B'),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    expect(collect($response->steps->first()->toolResults)->map->result->all())->toBe(['A', 'B']);
});

test('a tool that re-registers callbacks mid-batch does not corrupt the parent invoked events', function () {
    config(['concurrency.default' => 'sync']);

    $log = new ArrayObject;
    $loop = null;

    // Simulate a nested sub-agent re-registering callbacks on the shared loop while the batch runs.
    $clobber = function () use (&$loop) {
        $loop->onToolInvocation(fn () => null, fn () => null);
    };

    $tools = [
        new ParallelRecordingTool('alpha', $log, 'A'),
        new ParallelRecordingTool('bravo', $log, 'B', onHandle: $clobber),
    ];

    $gateway = parallelToolsGateway([
        new StepResponse('', [
            new ToolCall('call-a', 'alpha', [], 'call-a'),
            new ToolCall('call-b', 'bravo', [], 'call-b'),
        ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model'), continuationToken: 'r1'),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $loop = new TextGenerationLoop($gateway);

    $invoked = [];

    $loop->onToolInvocation(
        invoking: fn (Tool $tool, array $arguments, string $id) => null,
        invoked: function (Tool $tool, array $arguments, mixed $result, string $id) use (&$invoked) {
            $invoked[$tool->name()] = $id;
        },
    );

    $loop->generate(
        Mockery::mock(TextProvider::class),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new ParallelAgent),
        null,
    );

    expect($invoked)->toHaveKeys(['alpha', 'bravo']);
});

test('canRunInParallel resolves the legacy concurrency.driver key like the framework manager does', function () {
    $loop = new class(new ParallelToolsFakeGateway) extends TextGenerationLoop
    {
        public function canRun(): bool
        {
            return $this->canRunInParallel();
        }
    };

    config(['concurrency.default' => 'fork', 'concurrency.driver' => null]);
    $viaDefaultKey = $loop->canRun();

    config(['concurrency.default' => null, 'concurrency.driver' => 'fork']);
    $viaLegacyKey = $loop->canRun();

    expect($viaLegacyKey)->toBe($viaDefaultKey);
});

function parallelToolsGateway(array $steps = [], array $streams = []): ParallelToolsFakeGateway
{
    return new ParallelToolsFakeGateway($steps, $streams);
}

class ParallelAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function tools(): array
    {
        return [];
    }
}

class SequentialAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function tools(): array
    {
        return [];
    }
}

class ParallelRecordingTool implements Tool
{
    use CanBeConcurrent;

    public function __construct(
        public string $toolName,
        public ArrayObject $log,
        public string $output = 'ok',
        public bool $throws = false,
        public ?Closure $onHandle = null,
    ) {
        $this->concurrent();
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolName;
    }

    public function handle(Request $request): string
    {
        if ($this->throws) {
            throw new RuntimeException('boom:'.$this->toolName);
        }

        if ($this->onHandle !== null) {
            ($this->onHandle)();
        }

        $this->log[] = $this->toolName;

        return $this->output;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class SequentialRecordingTool extends ParallelRecordingTool
{
    public function __construct(
        string $toolName,
        ArrayObject $log,
        string $output = 'ok',
        bool $throws = false,
        ?Closure $onHandle = null,
    ) {
        parent::__construct($toolName, $log, $output, $throws, $onHandle);
        $this->concurrent(false);
    }

    public function handle(Request $request): string
    {
        return parent::handle($request);
    }
}

class ParallelToolsFakeGateway implements StepTextGateway
{
    /** @var StepContext[] */
    public array $contexts = [];

    public function __construct(
        public array $steps = [],
        public array $streams = [],
    ) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->contexts[] = $stepContext;

        return array_shift($this->steps);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $this->contexts[] = $stepContext;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}

class ThrowingConcurrencyDriver implements Driver
{
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        throw new RuntimeException('driver unavailable');
    }

    public function defer(Closure|array $tasks): DeferredCallback
    {
        return \Illuminate\Support\defer(fn () => null);
    }
}

class SerializingConcurrencyDriver implements Driver
{
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        return collect(Arr::wrap($tasks))->map(
            fn (Closure $task) => unserialize(serialize(new SerializableClosure($task)))->getClosure()()
        )->all();
    }

    public function defer(Closure|array $tasks): DeferredCallback
    {
        return \Illuminate\Support\defer(fn () => null);
    }
}
