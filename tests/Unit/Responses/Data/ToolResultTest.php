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

test('tool result to array includes the denied key only when denied', function (): void {
    expect((new ToolResult('id', 'name', [], 'val'))->toArray())->not->toHaveKey('denied')
        ->and((new ToolResult('id', 'name', [], 'val', denied: true))->toArray())->toHaveKey('denied', true);
});

test('tool result from array hydrates the denied flag from its key', function (): void {
    expect(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val'])->denied)->toBeFalse()
        ->and(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val', 'denied' => true])->denied)->toBeTrue();
});

test('tool result to array includes the failed key only when failed', function (): void {
    expect((new ToolResult('id', 'name', [], 'val'))->toArray())->not->toHaveKey('failed')
        ->and((new ToolResult('id', 'name', [], 'Tool not found', failed: true))->toArray())->toHaveKey('failed', true);
});

test('tool result from array hydrates the failed flag from its key', function (): void {
    expect(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val'])->failed)->toBeFalse()
        ->and(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'arguments' => [], 'result' => 'val', 'failed' => true])->failed)->toBeTrue();
});

test('tool result reports its error only when the call did not succeed', function (): void {
    expect((new ToolResult('id', 'name', [], 'Berlin'))->successful())->toBeTrue()
        ->and((new ToolResult('id', 'name', [], 'Berlin'))->error())->toBeNull()
        ->and((new ToolResult('id', 'name', [], 'Tool not found', failed: true))->successful())->toBeFalse()
        ->and((new ToolResult('id', 'name', [], 'Tool not found', failed: true))->error())->toBe('Tool not found')
        ->and((new ToolResult('id', 'name', [], 'Rejected', denied: true))->successful())->toBeFalse()
        ->and((new ToolResult('id', 'name', [], 'Rejected', denied: true))->error())->toBe('Rejected')
        ->and((new ToolResult('id', 'name', [], ['code' => 500], failed: true))->error())->toBeNull();
});
