<?php

use Laravel\Ai\Responses\Data\ToolCall;

test('tool call stores all properties', function () {
    $toolCall = new ToolCall(
        id: 'call_123',
        name: 'get_weather',
        arguments: ['city' => 'San Francisco'],
        resultId: 'res_456',
        reasoningId: 'reason_789',
        reasoningSummary: ['thought' => 'I should check the weather']
    );

    expect($toolCall->id)->toBe('call_123')
        ->and($toolCall->name)->toBe('get_weather')
        ->and($toolCall->arguments)->toBe(['city' => 'San Francisco'])
        ->and($toolCall->resultId)->toBe('res_456')
        ->and($toolCall->reasoningId)->toBe('reason_789')
        ->and($toolCall->reasoningSummary)->toBe(['thought' => 'I should check the weather']);
});

test('tool call to array returns all properties', function () {
    $toolCall = new ToolCall('id', 'name', ['arg' => 'val']);

    $array = $toolCall->toArray();

    expect($array)->toBe([
        'id' => 'id',
        'name' => 'name',
        'arguments' => ['arg' => 'val'],
        'result_id' => null,
        'reasoning_id' => null,
        'reasoning_summary' => null,
        'reasoning_signature' => null,
        'provider_executed' => false,
    ]);
});

test('tool call json serialize returns to array', function () {
    $toolCall = new ToolCall('id', 'name', []);

    expect($toolCall->jsonSerialize())->toBe($toolCall->toArray());
});
