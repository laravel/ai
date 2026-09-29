<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\UntrustedUrl;

test('a remote file pointing at a blocked address is never fetched', function (string $url): void {
    Http::fake();

    expect(fn () => (new RemoteImage($url))->content())->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'metadata endpoint' => 'http://169.254.169.254/latest/meta-data/',
    'loopback' => 'http://127.0.0.1:8000/secret',
    'private range' => 'http://10.0.0.5/admin',
    'cgnat range' => 'http://100.64.1.1/',
    'localhost' => 'http://localhost/',
    'local suffix' => 'http://metadata.google.internal.local/',
    'trailing dot' => 'http://localhost./',
    'ipv6 loopback' => 'http://[::1]/',
    'ipv4 mapped ipv6' => 'http://[::ffff:10.0.0.1]/',
    'nat64 embedded ipv4' => 'http://[64:ff9b::a00:1]/',
    'unique local ipv6' => 'http://[fd00::1]/',
    'unsupported scheme' => 'ftp://example.com/file',
]);

test('a hostname resolving to a private address is blocked', function (): void {
    Http::fake();

    UntrustedUrl::resolveUsing(fn (): array => ['93.184.216.34', '169.254.169.254']);

    expect(fn () => (new RemoteImage('https://rebinding.example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('a redirect to a blocked address is not followed', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class);

    Http::assertSentCount(1);
});

test('a redirect to a public address is followed', function (): void {
    Http::fake([
        'example.com/photo.png' => Http::response('', 301, ['Location' => '/moved/photo.png']),
        'example.com/moved/*' => Http::response('bytes', 200),
    ]);

    expect((new RemoteImage('https://example.com/photo.png'))->content())->toBe('bytes');

    Http::assertSentCount(2);
});

test('too many redirects throws', function (): void {
    Http::fake(['example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/again'])]);

    (new RemoteImage('https://example.com/photo.png'))->content();
})->throws(InvalidArgumentException::class, 'redirected too many times');

test('a public address is fetched without following redirects automatically', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('bytes', 200)]);

    expect((new RemoteImage('http://93.184.216.34/photo.png'))->content())->toBe('bytes');
});
