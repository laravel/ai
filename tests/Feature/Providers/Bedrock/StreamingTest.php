<?php

use Aws\MockHandler;
use Aws\Result;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Tools\FixedNumberGenerator;

describe('text streaming', function (): void {
    test('streaming handles reasoning and text blocks', function (): void {
        $client = $this->fakeBedrockStream([
            $this->contentBlockStart(0),
            $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'Let me think']]),
            $this->contentBlockDelta(0, ['reasoningContent' => ['text' => ' carefully']]),
            $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-abc']]),
            $this->contentBlockStop(0),
            $this->contentBlockStart(1),
            $this->contentBlockDelta(1, ['text' => 'Hello ']),
            $this->contentBlockDelta(1, ['text' => 'world']),
            $this->contentBlockStop(1),
            $this->messageStop('end_turn'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
            preserve_keys: false,
        );

        expect($events[0])->toBeInstanceOf(StreamStart::class)
            ->and($events[1])->toBeInstanceOf(ReasoningStart::class)
            ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me think')
            ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe(' carefully')
            ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
            ->and($events[5])->toBeInstanceOf(TextStart::class)
            ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello ')
            ->and($events[7])->toBeInstanceOf(TextDelta::class)->delta->toBe('world')
            ->and($events[8])->toBeInstanceOf(TextEnd::class)
            ->and($events[9])->toBeInstanceOf(StreamEnd::class);
    });

    test('each text block in a stream gets a distinct message id', function (): void {
        $client = $this->fakeBedrockStream([
            $this->contentBlockStart(0),
            $this->contentBlockDelta(0, ['text' => 'first']),
            $this->contentBlockStop(0),
            $this->contentBlockStart(1),
            $this->contentBlockDelta(1, ['text' => 'second']),
            $this->contentBlockStop(1),
            $this->messageStop('end_turn'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
            preserve_keys: false,
        );

        $textStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof TextStart));

        expect($textStarts)->toHaveCount(2)
            ->and($textStarts[0]->messageId)->not->toBe($textStarts[1]->messageId);
    });

    test('streaming round-trips reasoning block on follow-up tool step', function (): void {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-xyz']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $secondCall = $mock->getLastCommand()->toArray();
        $assistantTurn = $secondCall['messages'][0];

        expect($assistantTurn['role'])->toBe('assistant')
            ->and($assistantTurn['content'][0])->toBe([
                'reasoningContent' => [
                    'reasoningText' => [
                        'text' => 'I should call the tool',
                        'signature' => 'sig-xyz',
                    ],
                ],
            ])
            ->and($assistantTurn['content'][1]['toolUse']['toolUseId'])->toBe('t1')
            ->and($assistantTurn['content'][1]['toolUse']['name'])->toBe('FixedNumberGenerator');
    });

    test('streaming does not round-trip an empty text block on follow-up tool step', function () {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => '']),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        expect($assistantTurn['content'])->toHaveCount(1)
            ->and($assistantTurn['content'][0])->toHaveKey('toolUse');
    });

    test('streaming preserves empty text blocks between reasoning blocks on follow-up tool step', function () {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-1']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1),
                $this->contentBlockDelta(1, ['text' => '']),
                $this->contentBlockStop(1),
                $this->contentBlockStart(2),
                $this->contentBlockDelta(2, ['reasoningContent' => ['text' => 'I need the result']]),
                $this->contentBlockDelta(2, ['reasoningContent' => ['signature' => 'sig-2']]),
                $this->contentBlockStop(2),
                $this->contentBlockStart(3, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(3, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(3),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        expect($assistantTurn['content'])->toHaveCount(4)
            ->and($assistantTurn['content'][0])->toBe([
                'reasoningContent' => ['reasoningText' => ['text' => 'I should call the tool', 'signature' => 'sig-1']],
            ])
            ->and($assistantTurn['content'][1])->toBe(['text' => ''])
            ->and($assistantTurn['content'][2])->toBe([
                'reasoningContent' => ['reasoningText' => ['text' => 'I need the result', 'signature' => 'sig-2']],
            ])
            ->and($assistantTurn['content'][3]['toolUse']['toolUseId'])->toBe('t1')
            ->and($assistantTurn['content'][3]['toolUse']['name'])->toBe('FixedNumberGenerator');
    });

    test('streaming does not round-trip an empty reasoning block on follow-up tool step', function (array $delta) {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, $delta),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        expect($assistantTurn['content'])->toHaveCount(1)
            ->and($assistantTurn['content'][0])->toHaveKey('toolUse');
    })->with([
        'text' => [['reasoningContent' => ['text' => '']]],
        'signature' => [['reasoningContent' => ['signature' => '']]],
        'redacted content' => [['reasoningContent' => ['redactedContent' => '']]],
    ]);

    test('streaming omits an empty reasoning signature on follow-up tool step', function () {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'openai.gpt-oss-120b-1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        expect($assistantTurn['content'][0])->toBe([
            'reasoningContent' => [
                'reasoningText' => ['text' => 'I should call the tool'],
            ],
        ]);
    });

    test('streaming round-trips redacted reasoning block', function (): void {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['redactedContent' => 'redacted-bytes']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $secondCall = $mock->getLastCommand()->toArray();
        $assistantTurn = $secondCall['messages'][0];

        expect($assistantTurn['content'][0])->toBe([
            'reasoningContent' => ['redactedContent' => 'redacted-bytes'],
        ]);
    });
});
