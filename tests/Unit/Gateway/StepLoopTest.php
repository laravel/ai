<?php

use Illuminate\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function fakeProvider(): TextProvider
{
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn('fake');
    $provider->shouldReceive('driver')->andReturn('fake');

    return $provider;
}

function singleTurnResponse(string $text, array $toolCalls = [], FinishReason $finishReason = FinishReason::Stop, int $inputTokens = 10, int $outputTokens = 5): SingleTurnResponse
{
    return new SingleTurnResponse(
        text: $text,
        toolCalls: $toolCalls,
        finishReason: $finishReason,
        usage: new Usage($inputTokens, $outputTokens),
        meta: new Meta('fake', 'fake-model'),
    );
}

function fakeGateway(array $responses): TextGateway
{
    return new class($responses) implements TextGateway
    {
        private int $callIndex = 0;

        public int $callCount = 0;

        public function __construct(private array $responses) {}

        public function generateText(
            TextProvider $provider,
            string $model,
            ?string $instructions,
            array $messages = [],
            array $tools = [],
            ?array $schema = null,
            ?TextGenerationOptions $options = null,
            ?int $timeout = null,
            ?string $previousResponseId = null,
            ?StepContext $context = null,
        ): SingleTurnResponse {
            $this->callCount++;

            return $this->responses[$this->callIndex++];
        }

        public function streamText(
            string $invocationId,
            TextProvider $provider,
            string $model,
            ?string $instructions,
            array $messages = [],
            array $tools = [],
            ?array $schema = null,
            ?TextGenerationOptions $options = null,
            ?int $timeout = null,
            ?string $previousResponseId = null,
            ?StepContext $context = null,
        ): Generator {
            yield from [];
        }
    };
}

function createStepLoop(TextGateway $gateway): StepLoop
{
    return new StepLoop($gateway, new Dispatcher);
}

test('single turn with no tools returns text response', function () {
    $gateway = fakeGateway([
        singleTurnResponse('Hello world'),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', 'Be helpful',
        [new UserMessage('Hi')], [], null, null, null,
    );

    expect($response)->toBeInstanceOf(TextResponse::class)
        ->and($response->text)->toBe('Hello world')
        ->and($gateway->callCount)->toBe(1);
});

test('tool calls trigger follow-up requests', function () {
    $gateway = fakeGateway([
        singleTurnResponse('', [
            new ToolCall('call_1', 'FixedNumberGenerator', []),
        ], FinishReason::ToolCalls),
        singleTurnResponse('The number is 72019'),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', 'Be helpful',
        [new UserMessage('Generate a number')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    expect($response->text)->toBe('The number is 72019')
        ->and($gateway->callCount)->toBe(2);
});

test('usage is aggregated across steps', function () {
    $gateway = fakeGateway([
        singleTurnResponse('', [
            new ToolCall('call_1', 'FixedNumberGenerator', []),
        ], FinishReason::ToolCalls, 100, 20),
        singleTurnResponse('Done', [], FinishReason::Stop, 150, 30),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Go')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    expect($response->usage->promptTokens)->toBe(250)
        ->and($response->usage->completionTokens)->toBe(50);
});

test('max steps limits tool call depth', function () {
    $toolCallResponse = singleTurnResponse('', [
        new ToolCall('call_1', 'FixedNumberGenerator', []),
    ], FinishReason::ToolCalls);

    $gateway = fakeGateway([
        $toolCallResponse,
        $toolCallResponse,
        $toolCallResponse,
        $toolCallResponse,
        $toolCallResponse,
    ]);

    $loop = createStepLoop($gateway);

    $options = new TextGenerationOptions(maxSteps: 3);

    $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Go')],
        [new FixedNumberGenerator],
        null, $options, null,
    );

    expect($gateway->callCount)->toBe(3);
});

test('steps are recorded on the response', function () {
    $gateway = fakeGateway([
        singleTurnResponse('', [
            new ToolCall('call_1', 'FixedNumberGenerator', []),
        ], FinishReason::ToolCalls),
        singleTurnResponse('Final answer'),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Go')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    expect($response->steps)->toHaveCount(2)
        ->and($response->steps[0]->toolCalls)->toHaveCount(1)
        ->and($response->steps[0]->toolResults)->toHaveCount(1)
        ->and($response->steps[0]->toolResults[0]->result)->toBe('72019')
        ->and($response->steps[1]->text)->toBe('Final answer');
});

test('unmatched tool calls are skipped without error', function () {
    $gateway = fakeGateway([
        singleTurnResponse('', [
            new ToolCall('call_1', 'NonExistentTool', []),
        ], FinishReason::ToolCalls),
        singleTurnResponse('Recovered'),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Go')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    expect($response->text)->toBe('Recovered')
        ->and($response->steps[0]->toolResults)->toBeEmpty();
});

test('structured output is passed through from final step', function () {
    $gateway = fakeGateway([
        new SingleTurnResponse(
            text: '{"number": 42}',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(10, 5),
            meta: new Meta('fake', 'fake-model'),
            structured: ['number' => 42],
        ),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Give me a number')],
        [], ['number' => 'integer'], null, null,
    );

    expect($response->structured)->toBe(['number' => 42]);
});

test('provider-executed tool calls are not executed locally', function () {
    $gateway = fakeGateway([
        singleTurnResponse('', [
            new ToolCall('srvtoolu_1', 'web_search', ['query' => 'test'], providerExecuted: true),
            new ToolCall('call_1', 'FixedNumberGenerator', []),
        ], FinishReason::ToolCalls),
        singleTurnResponse('Done'),
    ]);

    $loop = createStepLoop($gateway);

    $response = $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Search and compute')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    // Only FixedNumberGenerator should have a result; web_search is provider-executed
    expect($response->steps[0]->toolResults)->toHaveCount(1)
        ->and($response->steps[0]->toolResults[0]->name)->toBe('FixedNumberGenerator')
        ->and($response->steps[0]->toolCalls)->toHaveCount(2);
});

test('messages accumulate across tool loop iterations', function () {
    $messagesPerCall = [];

    $gateway = new class($messagesPerCall) implements TextGateway
    {
        private int $callIndex = 0;

        public function __construct(private array &$messagesPerCall) {}

        public function generateText(
            TextProvider $provider,
            string $model,
            ?string $instructions,
            array $messages = [],
            array $tools = [],
            ?array $schema = null,
            ?TextGenerationOptions $options = null,
            ?int $timeout = null,
            ?string $previousResponseId = null,
            ?StepContext $context = null,
        ): SingleTurnResponse {
            $this->messagesPerCall[] = count($messages);

            if ($this->callIndex++ === 0) {
                return singleTurnResponse('', [
                    new ToolCall('call_1', 'FixedNumberGenerator', []),
                ], FinishReason::ToolCalls);
            }

            return singleTurnResponse('Done');
        }

        public function streamText(
            string $invocationId,
            TextProvider $provider,
            string $model,
            ?string $instructions,
            array $messages = [],
            array $tools = [],
            ?array $schema = null,
            ?TextGenerationOptions $options = null,
            ?int $timeout = null,
            ?string $previousResponseId = null,
            ?StepContext $context = null,
        ): Generator {
            yield from [];
        }
    };

    $loop = createStepLoop($gateway);

    $loop->generate(
        fakeProvider(), 'fake-model', null,
        [new UserMessage('Go')],
        [new FixedNumberGenerator],
        null, null, null,
    );

    // First call: 1 user message
    // Second call: 1 user + 1 assistant + 1 tool result = 3
    expect($messagesPerCall)->toBe([1, 3]);
});
