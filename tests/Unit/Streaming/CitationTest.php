<?php

use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\TextDelta;

function citation(string $url, ?string $title = null): Citation
{
    return new Citation(uniqid(), 'message-1', new UrlCitation($url, $title), time());
}

test('combine collects the sources a run cited', function () {
    $citations = Citation::combine([
        citation('https://laravel.com/docs', 'Laravel Documentation'),
        citation('https://laravel.com/docs/mcp', 'Laravel MCP'),
    ]);

    expect($citations)->toHaveCount(2)
        ->and($citations->first()->url)->toBe('https://laravel.com/docs')
        ->and($citations->first()->title)->toBe('Laravel Documentation');
});

test('combine keeps every mention, the way a generated response does', function () {
    // The generated path records every mention, so the streamed path does too...
    $citations = Citation::combine([
        citation('https://laravel.com/docs', 'Laravel Documentation'),
        citation('https://laravel.com/docs/mcp', 'Laravel MCP'),
        citation('https://laravel.com/docs', 'Laravel Documentation'),
    ]);

    expect($citations)->toHaveCount(3)
        ->and($citations->pluck('url')->all())->toBe([
            'https://laravel.com/docs',
            'https://laravel.com/docs/mcp',
            'https://laravel.com/docs',
        ]);
});

test('combine ignores events that are not citations', function () {
    $citations = Citation::combine([
        new TextDelta(uniqid(), 'message-1', 'Laravel MCP ships a server.', time()),
        citation('https://laravel.com/docs/mcp'),
    ]);

    expect($citations)->toHaveCount(1);
});

test('combine returns nothing when the run cited nothing', function () {
    expect(Citation::combine([
        new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
    ]))->toBeEmpty();
});
