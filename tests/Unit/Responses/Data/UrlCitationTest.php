<?php

use Laravel\Ai\Responses\Data\UrlCitation;

test('url citation stores url and title', function () {
    $citation = new UrlCitation('https://example.com', 'Example');

    expect($citation->url)->toBe('https://example.com')
        ->and($citation->title)->toBe('Example');
});

test('url citation to array returns url and title', function () {
    $citation = new UrlCitation('https://laravel.com', 'Laravel');

    $array = $citation->toArray();

    expect($array['url'])->toBe('https://laravel.com')
        ->and($array['title'])->toBe('Laravel');
});

test('url citation json serialize returns to array', function () {
    $citation = new UrlCitation('https://google.com');

    $json = $citation->jsonSerialize();

    expect($json['url'])->toBe('https://google.com')
        ->and($json['title'])->toBeNull();
});
