<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function bedrockToolCallResponse(string $toolUseId): array
{
    return [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => $toolUseId, 'name' => 'FixedNumberGenerator', 'input' => []]],
        ]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
        'stopReason' => 'tool_use',
    ];
}

function bedrockTextResponse(string $text): array
{
    return [
        'output' => ['message' => ['content' => [['text' => $text]]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ];
}

describe('tool call loop', function () {
    test('multi step tool loop returns accumulated response shape', function () {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            'invocation-1',
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 5),
        );

        expect((string) $response)->toBe('Done')
            ->and($response->messages)->toHaveCount(5)
            ->and($response->steps)->toHaveCount(3)
            ->and($response->toolCalls)->toHaveCount(2)
            ->and($response->toolResults)->toHaveCount(2)
            ->and($response->usage->promptTokens)->toBe(21)
            ->and($response->usage->completionTokens)->toBe(11);
    });

    test('max steps limits tool call depth', function () {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockToolCallResponse('t3'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            'invocation-1',
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        expect($response->steps)->toHaveCount(2);
    });

    test('unknown tool call throws NoSuchToolException', function () {
        $client = $this->fakeBedrockConverse([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 't1', 'name' => 'NonExistentTool', 'input' => []]],
            ]]],
            'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
            'stopReason' => 'tool_use',
        ]);

        $gateway = $this->gatewayWithClient($client);

        expect(fn () => (new TextGenerationLoop($gateway))->generate(
            'invocation-1',
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            tools: [new FixedNumberGenerator],
        ))->toThrow(NoSuchToolException::class);
    });

    test('structured output is parsed from the synthetic tool call', function () {
        $client = $this->fakeBedrockConverse([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 's1', 'name' => 'structured_output', 'input' => ['symbol' => 'Fe']]],
            ]]],
            'usage' => ['inputTokens' => 8, 'outputTokens' => 4],
            'stopReason' => 'tool_use',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            'invocation-1',
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            schema: ['symbol' => (new JsonSchemaTypeFactory)->string()],
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response->structured)->toMatchArray(['symbol' => 'Fe'])
            ->and($response->steps)->toHaveCount(1)
            ->and($response->usage->promptTokens)->toBe(8)
            ->and($response->usage->completionTokens)->toBe(4);
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function () {
        $client = $this->fakeBedrockStreamSequence([
            [
                $this->contentBlockStart(0, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(0, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(0),
                $this->messageStop('tool_use'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
            [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $streamEnds = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));
        $toolResults = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

        expect($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->reason)->toBe('stop')
            ->and($streamEnds[0]->usage->promptTokens)->toBe(10)
            ->and($streamEnds[0]->usage->completionTokens)->toBe(4)
            ->and($toolResults)->toHaveCount(1)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });
});
