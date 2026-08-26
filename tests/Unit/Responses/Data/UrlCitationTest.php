<?php

use Laravel\Ai\Responses\Data\UrlCitation;

test('url citation stores url and title', function (): void {
    $citation = new UrlCitation('https://example.com', 'Example');

    expect($citation->url)->toBe('https://example.com')
        ->and($citation->title)->toBe('Example')
        ->and($citation->startIndex)->toBeNull()
        ->and($citation->endIndex)->toBeNull();
});

test('url citation stores span indices when provided', function (): void {
    $citation = new UrlCitation('https://example.com', 'Example', startIndex: 12, endIndex: 45);

    expect($citation->startIndex)->toBe(12)
        ->and($citation->endIndex)->toBe(45);
});

test('url citation to array returns all fields', function (): void {
    $citation = new UrlCitation('https://laravel.com', 'Laravel', startIndex: 0, endIndex: 7);

    expect($citation->toArray())->toBe([
        'url' => 'https://laravel.com',
        'title' => 'Laravel',
        'start_index' => 0,
        'end_index' => 7,
        'ranges' => [['start' => 0, 'end' => 7]],
    ]);
});

test('url citation json serialize returns to array', function (): void {
    $citation = new UrlCitation('https://google.com');

    expect($citation->jsonSerialize())->toBe([
        'url' => 'https://google.com',
        'title' => null,
        'start_index' => null,
        'end_index' => null,
        'ranges' => [],
    ]);
});

test('url citation initializes ranges from constructor indices', function (): void {
    $citation = new UrlCitation('https://example.com', startIndex: 5, endIndex: 15);

    expect($citation->ranges->all())->toBe([['start' => 5, 'end' => 15]]);
});

test('url citation has empty ranges when no indices given', function (): void {
    $citation = new UrlCitation('https://example.com');

    expect($citation->ranges->isEmpty())->toBeTrue();
});

test('url citation addRange adds a range and sets startIndex and endIndex on first call', function (): void {
    $citation = new UrlCitation('https://example.com');

    $citation->addRange(10, 20);

    expect($citation->startIndex)->toBe(10)
        ->and($citation->endIndex)->toBe(20)
        ->and($citation->ranges->all())->toBe([['start' => 10, 'end' => 20]]);
});

test('url citation addRange accumulates multiple ranges', function (): void {
    $citation = new UrlCitation('https://example.com');

    $citation->addRange(0, 10);
    $citation->addRange(20, 30);

    expect($citation->ranges->all())->toBe([['start' => 0, 'end' => 10], ['start' => 20, 'end' => 30]])
        ->and($citation->startIndex)->toBe(0)
        ->and($citation->endIndex)->toBe(10);
});

test('url citation addRange ignores duplicate ranges', function (): void {
    $citation = new UrlCitation('https://example.com');

    $citation->addRange(5, 15);
    $citation->addRange(5, 15);

    expect($citation->ranges->count())->toBe(1);
});

test('url citation addRange ignores null indices', function (): void {
    $citation = new UrlCitation('https://example.com');

    $citation->addRange(null, 10);
    $citation->addRange(5, null);
    $citation->addRange(null, null);

    expect($citation->ranges->isEmpty())->toBeTrue()
        ->and($citation->startIndex)->toBeNull()
        ->and($citation->endIndex)->toBeNull();
});
