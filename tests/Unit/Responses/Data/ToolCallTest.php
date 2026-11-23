<?php

use Laravel\Ai\Responses\Data\ToolCall;

test('tool call stores all properties', function (): void {
    $toolCall = new ToolCall(
        id: 'call_123',
        name: 'get_weather',
        arguments: ['city' => 'San Francisco'],
        resultId: 'res_456',
        reasoningId: 'reason_789',
        reasoningSummary: ['thought' => 'I should check the weather'],
        reasoningEncryptedContent: 'enc-blob-1',
        thoughtSignature: 'sig-1',
        thinkingBlocks: [['type' => 'thinking', 'thinking' => 'Checking.', 'signature' => 'sig-2']],
        thinkingFingerprint: 'fp-1',
    );

    expect($toolCall->id)->toBe('call_123')
        ->and($toolCall->name)->toBe('get_weather')
        ->and($toolCall->arguments)->toBe(['city' => 'San Francisco'])
        ->and($toolCall->resultId)->toBe('res_456')
        ->and($toolCall->reasoningId)->toBe('reason_789')
        ->and($toolCall->reasoningSummary)->toBe(['thought' => 'I should check the weather'])
        ->and($toolCall->reasoningEncryptedContent)->toBe('enc-blob-1')
        ->and($toolCall->thoughtSignature)->toBe('sig-1')
        ->and($toolCall->thinkingBlocks)->toBe([['type' => 'thinking', 'thinking' => 'Checking.', 'signature' => 'sig-2']])
        ->and($toolCall->thinkingFingerprint)->toBe('fp-1');
});

test('tool call to array returns all properties', function (): void {
    $toolCall = new ToolCall('id', 'name', ['arg' => 'val']);

    $array = $toolCall->toArray();

    expect($array)->toBe([
        'id' => 'id',
        'name' => 'name',
        'arguments' => ['arg' => 'val'],
        'result_id' => null,
        'reasoning_id' => null,
        'reasoning_summary' => null,
        'reasoning_encrypted_content' => null,
        'thought_signature' => null,
        'thinking_blocks' => null,
        'thinking_fingerprint' => null,
    ]);
});

test('tool call json serialize returns to array', function (): void {
    $toolCall = new ToolCall('id', 'name', []);

    expect($toolCall->jsonSerialize())->toBe($toolCall->toArray());
});

test('tool call round trips its thinking through to array', function (): void {
    $toolCall = new ToolCall('id', 'name', [], thinkingBlocks: [['type' => 'redacted_thinking', 'data' => 'opaque']], thinkingFingerprint: 'fp-1');

    $restored = ToolCall::fromArray($toolCall->toArray());

    expect($restored->thinkingBlocks)->toBe([['type' => 'redacted_thinking', 'data' => 'opaque']])
        ->and($restored->thinkingFingerprint)->toBe('fp-1');
});
