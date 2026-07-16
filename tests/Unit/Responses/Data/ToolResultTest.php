<?php

use Laravel\Ai\Responses\Data\ToolResult;

test('tool result stores all properties', function (): void {
    $result = new ToolResult(
        id: 'call_123',
        name: 'get_weather',
        arguments: ['city' => 'London'],
        result: ['temp' => 20],
        resultId: 'msg_789'
    );

    expect($result->id)->toBe('call_123')
        ->and($result->name)->toBe('get_weather')
        ->and($result->arguments)->toBe(['city' => 'London'])
        ->and($result->result)->toBe(['temp' => 20])
        ->and($result->resultId)->toBe('msg_789');
});

test('tool result to array returns all properties', function (): void {
    $result = new ToolResult('id', 'name', [], 'raw result');

    $array = $result->toArray();

    expect($array)->toBe([
        'id' => 'id',
        'name' => 'name',
        'arguments' => [],
        'result' => 'raw result',
        'result_id' => null,
    ]);
});

test('tool result json serialize returns to array', function (): void {
    $result = new ToolResult('id', 'name', [], 'val');

    expect($result->jsonSerialize())->toBe($result->toArray());
});

test('tool result to array omits the denied key when not denied', function (): void {
    $result = new ToolResult('id', 'name', [], 'val');

    expect($result->toArray())->not->toHaveKey('denied');
});

test('tool result to array includes the denied key when denied', function (): void {
    $result = new ToolResult('id', 'name', [], 'val', denied: true);

    expect($result->toArray())->toHaveKey('denied', true);
});

test('tool result from array hydrates denied as false when the key is missing', function (): void {
    $result = ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val']);

    expect($result->denied)->toBeFalse();
});

test('tool result from array hydrates denied as true when the key is present', function (): void {
    $result = ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val', 'denied' => true]);

    expect($result->denied)->toBeTrue();
});
