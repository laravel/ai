<?php

use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredDocument;
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

test('tool result text serializes array results without escaping slashes or unicode', function (): void {
    expect((new ToolResult('id', 'name', [], ['url' => 'https://example.com/report', 'city' => 'Genève']))->text())
        ->toBe('{"url":"https://example.com/report","city":"Genève"}');
});

test('tool result text passes through strings and casts everything else', function (): void {
    expect((new ToolResult('id', 'name', [], 'https://example.com/report'))->text())->toBe('https://example.com/report')
        ->and((new ToolResult('id', 'name', [], 72))->text())->toBe('72');
});

test('tool result to array includes the attachments key only when a file is attached', function (): void {
    $result = new ToolResult('id', 'name', [], 'chart.png', attachments: [Image::fromStorage('charts/chart.png', 's3')]);

    expect((new ToolResult('id', 'name', [], 'val'))->toArray())->not->toHaveKey('attachments')
        ->and($result->toArray()['attachments'])->toBe([
            ['type' => 'stored-image', 'name' => 'chart.png', 'path' => 'charts/chart.png', 'disk' => 's3'],
        ]);
});

test('tool result from array rehydrates its attachments', function (): void {
    $result = ToolResult::fromArray((new ToolResult('id', 'name', [], 'chart.png', attachments: [
        Image::fromBase64('cG5n', 'image/png')->as('chart.png'),
        Document::fromStorage('invoice.pdf', 's3'),
    ]))->toArray());

    expect($result->attachments)->toHaveCount(2)
        ->and($result->attachments[0])->toBeInstanceOf(Base64Image::class)
        ->and($result->attachments[0]->name())->toBe('chart.png')
        ->and($result->attachments[1])->toBeInstanceOf(StoredDocument::class)
        ->and($result->attachments[1]->disk)->toBe('s3')
        ->and(ToolResult::fromArray(['id' => 'id', 'name' => 'name', 'result' => 'val'])->attachments)->toBe([]);
});

test('tool result text notes each attached file a text only provider cannot receive', function (): void {
    $result = new ToolResult('id', 'name', [], 'chart.png', attachments: [
        Image::fromBase64('cG5n', 'image/png')->as('chart.png'),
        Document::fromBase64('cGRm', 'application/pdf'),
    ]);

    expect($result->text())->toBe(implode("\n", [
        'chart.png',
        '[The image chart.png was left out because it could not be sent to this provider.]',
        '[The document was left out because it could not be sent to this provider.]',
    ]))->and($result->resultText())->toBe('chart.png');
});
