<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;

test('it does not execute tool calls on the final generation step', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($gateway->contexts[0]->isFinalStep)->toBeTrue()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(0)
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->toolResults)->toBe([]);
});

test('it holds stream end until the streamed tool loop is complete', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $firstToolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $firstToolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$firstToolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
            returns: new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model'), continuationToken: 'response-2'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($tool->calls)->toBe(1)
        ->and($gateway->streamCalls)->toBe(2)
        ->and($streamEnds)->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds->first()->usage->promptTokens)->toBe(15)
        ->and($streamEnds->first()->usage->completionTokens)->toBe(3);
});

test('it does not execute streamed tool calls on the final step', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
    ));

    expect($tool->calls)->toBe(0)
        ->and($gateway->streamCalls)->toBe(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it clamps non-positive maxSteps to at least one turn', function (int $maxSteps): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: 'hi',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(1, 1),
            meta: new Meta('fake', 'model'),
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        new TextGenerationOptions(maxSteps: $maxSteps),
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('hi');
})->with([
    'zero' => 0,
    'negative' => -3,
]);

test('it accumulates streamed usage across multi-step turns', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('delta', 'msg-1', 'done', time())],
            returns: new StepResponse(text: 'done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
    ));

    $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->usage->promptTokens)->toBe(15)
        ->and($streamEnd->usage->completionTokens)->toBe(3)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value);
});

test('it throws when generation tool calls do not match local tools', function (): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', 'MissingTool', [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    expect(fn (): TextResponse => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
    ))->toThrow(NoSuchToolException::class, "Model tried to call unavailable tool 'MissingTool'.");
});

test('it throws when streaming tool calls do not match local tools', function (): void {
    $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    expect(fn (): array => iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
    )))->toThrow(NoSuchToolException::class);
});

test('it emits a terminal stream end when a turn yields no stream end or error', function (): void {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new TextDelta('text-delta', 'message-1', 'partial', time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Error->value);
});

test('it does not emit a stream end when a turn errors without a stream end', function (): void {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new Error('error-1', 'server_error', 'Server overloaded', false, time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
    ));

    expect(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(Error::class))->toHaveCount(1);
});

function textGenerationLoopProvider(): TextProvider
{
    return Mockery::mock(TextProvider::class);
}

/** @param  array<int, object>  $events */
function textGenerationLoopStreamStep(array $events = [], ?StepResponse $returns = null): array
{
    return [$events, $returns];
}

class TextGenerationLoopFakeGateway implements StepTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

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
        $this->generateCalls++;
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
        $this->streamCalls++;
        $this->contexts[] = $stepContext;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}

class TextGenerationLoopCountingTool implements Tool
{
    public int $calls = 0;

    public function description(): string
    {
        return 'Counts invocations.';
    }

    public function handle(Request $request): string
    {
        $this->calls++;

        return 'counted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
