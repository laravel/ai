<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\RemoteImage;

test('mime type falls back to the response content type', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/webp; charset=binary']),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBe('image/webp');
});

test('mime type is null when the response declares none', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBeNull();
});

test('declared mime type wins without fetching the url', function (): void {
    Http::fake();

    expect((new RemoteImage('https://example.com/photo', 'image/png'))->mimeType())->toBe('image/png');

    Http::assertNothingSent();
});

test('a failed fetch throws instead of inlining the error body', function (): void {
    Http::fake([
        'example.com/*' => Http::response('Not Found', 404),
    ]);

    (new RemoteImage('https://example.com/missing.png'))->content();
})->throws(RequestException::class);

test('the remote url is only fetched once', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $image = new RemoteImage('https://example.com/photo.png');

    $image->mimeType();
    $image->content();

    Http::assertSentCount(1);
});
