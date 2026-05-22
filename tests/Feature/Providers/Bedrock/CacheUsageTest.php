<?php

use Laravel\Ai\Streaming\Events\StreamEnd;
use Tests\Fixtures\Tools\FixedNumberGenerator;

describe('bedrock cache usage capture', function () {
    test('generateText captures cache read and write tokens from converse response usage', function () {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [['text' => 'Hi']],
                ],
            ],
            'usage' => [
                'inputTokens' => 100,
                'outputTokens' => 50,
                'cacheReadInputTokens' => 80,
                'cacheWriteInputTokens' => 20,
            ],
            'stopReason' => 'end_turn',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateText(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        expect($response->usage->promptTokens)->toBe(100)
            ->and($response->usage->completionTokens)->toBe(50)
            ->and($response->usage->cacheReadInputTokens)->toBe(80)
            ->and($response->usage->cacheWriteInputTokens)->toBe(20);
    });

    test('generateText sums cache tokens across tool-loop steps', function () {
        $client = $this->fakeBedrockConverseSequence([
            [
                'output' => [
                    'message' => [
                        'content' => [[
                            'toolUse' => [
                                'toolUseId' => 't1',
                                'name' => 'FixedNumberGenerator',
                                'input' => [],
                            ],
                        ]],
                    ],
                ],
                'usage' => [
                    'inputTokens' => 30,
                    'outputTokens' => 15,
                    'cacheReadInputTokens' => 25,
                    'cacheWriteInputTokens' => 10,
                ],
                'stopReason' => 'tool_use',
            ],
            [
                'output' => [
                    'message' => [
                        'content' => [['text' => 'done']],
                    ],
                ],
                'usage' => [
                    'inputTokens' => 40,
                    'outputTokens' => 20,
                    'cacheReadInputTokens' => 35,
                    'cacheWriteInputTokens' => 5,
                ],
                'stopReason' => 'end_turn',
            ],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateText(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            tools: [new FixedNumberGenerator],
        );

        expect($response->usage->promptTokens)->toBe(70)
            ->and($response->usage->completionTokens)->toBe(35)
            ->and($response->usage->cacheReadInputTokens)->toBe(60)
            ->and($response->usage->cacheWriteInputTokens)->toBe(15);
    });

    test('streamText captures cache read and write tokens from metadata event', function () {
        $client = $this->fakeBedrockStream([
            $this->contentBlockStart(0),
            $this->contentBlockDelta(0, ['text' => 'Hi']),
            $this->contentBlockStop(0),
            $this->messageStop('end_turn'),
            ['metadata' => ['usage' => [
                'inputTokens' => 200,
                'outputTokens' => 75,
                'cacheReadInputTokens' => 150,
                'cacheWriteInputTokens' => 40,
            ]]],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            $gateway->streamText('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
            preserve_keys: false,
        );

        $streamEnd = collect($events)->first(fn ($e) => $e instanceof StreamEnd);

        expect($streamEnd)->not->toBeNull()
            ->and($streamEnd->usage->promptTokens)->toBe(200)
            ->and($streamEnd->usage->completionTokens)->toBe(75)
            ->and($streamEnd->usage->cacheReadInputTokens)->toBe(150)
            ->and($streamEnd->usage->cacheWriteInputTokens)->toBe(40);
    });

    test('missing cache token fields default to zero without throwing', function () {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [['text' => 'Hi']],
                ],
            ],
            'usage' => [
                'inputTokens' => 10,
                'outputTokens' => 5,
            ],
            'stopReason' => 'end_turn',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateText(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        expect($response->usage->cacheReadInputTokens)->toBe(0)
            ->and($response->usage->cacheWriteInputTokens)->toBe(0);
    });
});
