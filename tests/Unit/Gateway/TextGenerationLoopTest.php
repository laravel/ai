<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TurnTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Gateway\TurnResponse;
use Laravel\Ai\Gateway\TurnStreamEnd;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;

test('it does not execute tool calls on the final generation step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new TurnResponse(
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
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($gateway->contexts[0]->isFinalStep)->toBeTrue()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(0)
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->toolResults)->toBe([]);
});

test('it holds stream end until the streamed tool loop is complete', function () {
    $tool = new TextGenerationLoopCountingTool;
    $firstToolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        [
            new ToolCallEvent('tool-call-event', $firstToolCall, time()),
            new TurnStreamEnd(FinishReason::ToolCalls, new Usage(10, 1), continuationToken: 'response-1'),
        ],
        [
            new TextDelta('text-delta', 'message-1', 'Done', time()),
            new TurnStreamEnd(FinishReason::Stop, new Usage(5, 2), continuationToken: 'response-2'),
        ],
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
        null,
    ));

    $streamEndEvents = array_values(array_filter($events, fn ($event) => $event instanceof StreamEnd));
    $toolResultEvents = array_values(array_filter($events, fn ($event) => $event instanceof ToolResultEvent));

    expect($tool->calls)->toBe(1)
        ->and($gateway->streamCalls)->toBe(2)
        ->and($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEndEvents[0]->usage->promptTokens)->toBe(15)
        ->and($streamEndEvents[0]->usage->completionTokens)->toBe(3)
        ->and($toolResultEvents)->toHaveCount(1);
});

test('it does not execute streamed tool calls on the final step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway(streams: [[
        new ToolCallEvent('tool-call-event', new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1'), time()),
        new TurnStreamEnd(FinishReason::ToolCalls, new Usage(10, 1), continuationToken: 'response-1'),
    ]]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    ));

    expect($tool->calls)->toBe(0)
        ->and($gateway->streamCalls)->toBe(1)
        ->and(array_filter($events, fn ($event) => $event instanceof ToolResultEvent))->toHaveCount(0)
        ->and(array_filter($events, fn ($event) => $event instanceof StreamEnd))->toHaveCount(1);
});

test('it clamps non-positive maxSteps to at least one turn', function (int $maxSteps) {
    $gateway = new TextGenerationLoopFakeGateway([
        new TurnResponse(
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
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('hi');
})->with([
    'zero' => 0,
    'negative' => -3,
]);

test('it accumulates streamed usage across multi-step turns', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        [
            new ToolCallEvent('tool-call', new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1'), time()),
            new TurnStreamEnd(FinishReason::ToolCalls, new Usage(10, 1)),
        ],
        [
            new TextDelta('delta', 'msg-1', 'done', time()),
            new TurnStreamEnd(FinishReason::Stop, new Usage(5, 2)),
        ],
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
        null,
    ));

    $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

    expect($streamEnd)->not->toBeNull()
        ->and($streamEnd->usage->promptTokens)->toBe(15)
        ->and($streamEnd->usage->completionTokens)->toBe(3)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value);
});

test('it stops generation when tool calls do not match local tools', function () {
    $gateway = new TextGenerationLoopFakeGateway([
        new TurnResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', 'MissingTool', [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
        new TurnResponse(
            text: 'should not be requested',
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
        null,
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(0)
        ->and($response->steps)->toHaveCount(1);
});

test('it stops streaming when tool calls do not match local tools', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        [
            new ToolCallEvent('tool-call-event', new ToolCall('call-1', 'MissingTool', [], 'call-1'), time()),
            new TurnStreamEnd(FinishReason::ToolCalls, new Usage(10, 1), continuationToken: 'response-1'),
        ],
        [
            new TextDelta('text-delta', 'message-1', 'should not be requested', time()),
            new TurnStreamEnd(FinishReason::Stop, new Usage(1, 1)),
        ],
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

    expect($gateway->streamCalls)->toBe(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(0)
        ->and($streamEnd)->not->toBeNull()
        ->and($streamEnd->reason)->toBe(FinishReason::ToolCalls->value);
});

test('it emits a terminal stream end when a turn yields no stream end or error', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [[
        new TextDelta('text-delta', 'message-1', 'partial', time()),
    ]]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    $streamEndEvents = array_values(array_filter($events, fn ($event) => $event instanceof StreamEnd));

    expect($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Error->value);
});

test('it does not emit a stream end when a turn errors without a stream end', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [[
        new Error('error-1', 'server_error', 'Server overloaded', false, time()),
    ]]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    expect(array_filter($events, fn ($event) => $event instanceof StreamEnd))->toHaveCount(0)
        ->and(array_filter($events, fn ($event) => $event instanceof Error))->toHaveCount(1);
});

function textGenerationLoopProvider(): TextProvider
{
    return new class implements TextProvider
    {
        public function prompt(AgentPrompt $prompt): AgentResponse
        {
            throw new LogicException('Not used.');
        }

        public function stream(AgentPrompt $prompt): StreamableAgentResponse
        {
            throw new LogicException('Not used.');
        }

        public function textGateway(): TextGateway
        {
            throw new LogicException('Not used.');
        }

        public function useTextGateway(TextGateway $gateway): self
        {
            throw new LogicException('Not used.');
        }

        public function defaultTextModel(): string
        {
            return 'model';
        }

        public function cheapestTextModel(): string
        {
            return 'model';
        }

        public function smartestTextModel(): string
        {
            return 'model';
        }
    };
}

class TextGenerationLoopFakeGateway implements TurnTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /** @var StepContext[] */
    public array $contexts = [];

    public function __construct(
        public array $turns = [],
        public array $streams = [],
    ) {}

    public function handleTurn(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): TurnResponse {
        $this->generateCalls++;
        $this->contexts[] = $stepContext;

        return array_shift($this->turns);
    }

    public function streamTurn(
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

        foreach (array_shift($this->streams) as $event) {
            yield $event;
        }
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
