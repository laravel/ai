<?php

use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Tools\FixedNumberGenerator;

describe('text streaming', function () {
    test('streaming emits structural events around reasoning and text blocks', function () {
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
            $gateway->streamText('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
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

    test('streaming emits one stream_start per converse step', function () {
        $client = $this->fakeBedrockStreamSequence([
            [
                $this->contentBlockStart(0, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(0, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(0),
                $this->messageStop('tool_use'),
            ],
            [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            $gateway->streamText(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $streamStarts = array_filter($events, fn ($e) => $e instanceof StreamStart);

        expect($streamStarts)->toHaveCount(2);
    });
});
