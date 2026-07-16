<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;

test('stored document toArray falls back to basename without touching the disk', function (): void {
    Storage::fake('docs');

    $array = Document::fromStorage('invoices/invoice-2026-04.pdf', 'docs')->toArray();

    expect($array)->toBe([
        'type' => 'stored-document',
        'name' => 'invoice-2026-04.pdf',
        'path' => 'invoices/invoice-2026-04.pdf',
        'disk' => 'docs',
    ]);
});

test('stored audio toArray falls back to basename', function (): void {
    Storage::fake('docs');

    $array = Audio::fromStorage('clips/hello.mp3', 'docs')->toArray();

    expect($array['type'])->toBe('stored-audio');
    expect($array['name'])->toBe('hello.mp3');
    expect($array)->not->toHaveKey('mime');
});

test('stored image toArray falls back to basename', function (): void {
    Storage::fake('docs');

    $array = Image::fromStorage('photos/avatar.png', 'docs')->toArray();

    expect($array['type'])->toBe('stored-image');
    expect($array['name'])->toBe('avatar.png');
    expect($array)->not->toHaveKey('mime');
});

test('stored video toArray falls back to basename', function (): void {
    Storage::fake('docs');

    $array = Video::fromStorage('clips/demo.mp4', 'docs')->toArray();

    expect($array['type'])->toBe('stored-video');
    expect($array['name'])->toBe('demo.mp4');
    expect($array)->not->toHaveKey('mime');
});

test('local image toArray uses basename and the raw mime property', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'data');

    try {
        $array = Image::fromPath($path)->toArray();

        expect($array['type'])->toBe('local-image');
        expect($array['name'])->toBe(basename($path));
        expect($array['path'])->toBe($path);
        expect($array['mime'])->toBeNull();
    } finally {
        @unlink($path);
    }
});

test('local image toArray returns the explicitly set mime type', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'data');

    try {
        $array = Image::fromPath($path)->withMimeType('image/custom')->toArray();

        expect($array['mime'])->toBe('image/custom');
    } finally {
        @unlink($path);
    }
});

test('local video toArray uses basename and the raw mime property', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-video');
    file_put_contents($path, 'data');

    try {
        $array = Video::fromPath($path)->toArray();

        expect($array['type'])->toBe('local-video');
        expect($array['name'])->toBe(basename($path));
        expect($array['path'])->toBe($path);
        expect($array['mime'])->toBeNull();
    } finally {
        @unlink($path);
    }
});

test('local video toArray returns the explicitly set mime type', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-video');
    file_put_contents($path, 'data');

    try {
        $array = Video::fromPath($path)->withMimeType('video/custom')->toArray();

        expect($array['mime'])->toBe('video/custom');
    } finally {
        @unlink($path);
    }
});

test('base64 document toArray reflects name and mime', function (): void {
    $doc = Document::fromString('hello world', 'text/plain')->as('greeting.txt');

    expect($doc->toArray())->toMatchArray([
        'type' => 'base64-document',
        'name' => 'greeting.txt',
        'mime' => 'text/plain',
    ]);
});

test('base64 video toArray reflects name and mime', function (): void {
    $video = Video::fromBase64(base64_encode('video'), 'video/mp4')->as('demo.mp4');

    expect($video->toArray())->toMatchArray([
        'type' => 'base64-video',
        'name' => 'demo.mp4',
        'mime' => 'video/mp4',
    ]);
});

test('remote document toArray never issues an HTTP request', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $array = Document::fromUrl('https://example.com/signed/private.pdf')->toArray();

    expect($array)->toBe([
        'type' => 'remote-document',
        'name' => 'private.pdf',
        'url' => 'https://example.com/signed/private.pdf',
        'mime' => null,
    ]);

    Http::assertNothingSent();
});

test('remote image toArray returns the explicitly set mime type without HTTP calls', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $array = Image::fromUrl('https://example.com/avatar.png')->withMimeType('image/png')->toArray();

    expect($array['mime'])->toBe('image/png');
    expect($array['name'])->toBe('avatar.png');

    Http::assertNothingSent();
});

test('remote video toArray returns the explicitly set mime type without HTTP calls', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $array = Video::fromUrl('https://example.com/demo.mp4')->withMimeType('video/mp4')->toArray();

    expect($array['mime'])->toBe('video/mp4');
    expect($array['name'])->toBe('demo.mp4');

    Http::assertNothingSent();
});

test('remote document toArray handles urls without a path component', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $array = Document::fromUrl('https://example.com')->toArray();
    } finally {
        restore_error_handler();
    }

    expect($array['name'])->toBe('');
    expect($array['mime'])->toBeNull();

    Http::assertNothingSent();
});

test('local image toArray does not touch the filesystem', function (): void {
    $path = '/tmp/this-file-definitely-does-not-exist-'.uniqid().'.png';

    $array = Image::fromPath($path)->toArray();

    expect($array)->toBe([
        'type' => 'local-image',
        'name' => basename($path),
        'path' => $path,
        'mime' => null,
    ]);
});
