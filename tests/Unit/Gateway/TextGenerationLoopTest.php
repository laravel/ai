<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\SingleTurnTextGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;

test('it does not execute tool calls on the final generation step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new SingleTurnResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
            responseId: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway, new Dispatcher))->generate(
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
            new StreamEnd('first-end', FinishReason::ToolCalls->value, new Usage(10, 1), time(), responseId: 'response-1'),
        ],
        [
            new TextDelta('text-delta', 'message-1', 'Done', time()),
            new StreamEnd('final-end', FinishReason::Stop->value, new Usage(5, 2), time(), responseId: 'response-2'),
        ],
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway, new Dispatcher))->stream(
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
        ->and($streamEndEvents[0]->id)->toBe('final-end')
        ->and($toolResultEvents)->toHaveCount(1);
});

test('it does not execute streamed tool calls on the final step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway(streams: [[
        new ToolCallEvent('tool-call-event', new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1'), time()),
        new StreamEnd('final-end', FinishReason::ToolCalls->value, new Usage(10, 1), time(), responseId: 'response-1'),
    ]]);

    $events = iterator_to_array((new TextGenerationLoop($gateway, new Dispatcher))->stream(
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

class TextGenerationLoopFakeGateway implements SingleTurnTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /** @var StepContext[] */
    public array $contexts = [];

    public function __construct(
        public array $turns = [],
        public array $streams = [],
    ) {}

    public function generateSingleTurn(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): SingleTurnResponse {
        $this->generateCalls++;
        $this->contexts[] = $stepContext;

        return array_shift($this->turns);
    }

    public function streamSingleTurn(
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
