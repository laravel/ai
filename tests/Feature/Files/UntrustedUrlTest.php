<?php

use Illuminate\Http\Client\Request;
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
    'local suffix' => 'http://printer.local/',
    'trailing dot' => 'http://localhost./',
    'ipv6 loopback' => 'http://[::1]/',
    'ipv4 mapped ipv6' => 'http://[::ffff:10.0.0.1]/',
    'nat64 embedded ipv4' => 'http://[64:ff9b::a00:1]/',
    'local-use nat64' => 'http://[64:ff9b:1::a00:1]/',
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

test('the connection is pinned to the validated addresses', function (): void {
    $pinned = null;

    Http::fake(function (Request $request, array $options) use (&$pinned) {
        $pinned = $options['curl'][CURLOPT_RESOLVE];

        return Http::response('bytes');
    });

    (new RemoteImage('https://example.com/photo.png'))->content();

    expect($pinned)->toBe(['example.com:443:93.184.216.34']);
});

test('a public ip literal is fetched without pinning', function (string $url): void {
    $pinned = null;

    Http::fake(function (Request $request, array $options) use (&$pinned) {
        $pinned = $options['curl'][CURLOPT_RESOLVE];

        return Http::response('bytes');
    });

    expect((new RemoteImage($url))->content())->toBe('bytes')
        ->and($pinned)->toBe([]);
})->with([
    'ipv4' => 'http://93.184.216.34/photo.png',
    'nat64 embedded public ipv4' => 'http://[64:ff9b::808:808]/photo.png',
]);

test('an allowed host skips the private address check', function (): void {
    config(['ai.remote_files.allowed_hosts' => ['minio']]);

    UntrustedUrl::resolveUsing(fn (): array => ['172.18.0.2']);

    Http::fake(['minio:9000/*' => Http::response('bytes', 200)]);

    expect((new RemoteImage('http://minio:9000/bucket/photo.png'))->content())->toBe('bytes');
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

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'redirected too many times');

    Http::assertSentCount(6);
});
