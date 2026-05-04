<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Files\InlineFile;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

test('stored document toArray falls back to basename without touching the disk', function () {
    Storage::fake('docs');

    $array = Document::fromStorage('invoices/invoice-2026-04.pdf', 'docs')->toArray();

    expect($array)->toBe([
        'type' => 'stored-document',
        'name' => 'invoice-2026-04.pdf',
        'path' => 'invoices/invoice-2026-04.pdf',
        'disk' => 'docs',
    ]);
});

test('stored audio toArray falls back to basename', function () {
    Storage::fake('docs');

    $array = Audio::fromStorage('clips/hello.mp3', 'docs')->toArray();

    expect($array['type'])->toBe('stored-audio');
    expect($array['name'])->toBe('hello.mp3');
    expect($array)->not->toHaveKey('mime');
});

test('stored image toArray falls back to basename', function () {
    Storage::fake('docs');

    $array = Image::fromStorage('photos/avatar.png', 'docs')->toArray();

    expect($array['type'])->toBe('stored-image');
    expect($array['name'])->toBe('avatar.png');
    expect($array)->not->toHaveKey('mime');
});

test('local image toArray uses basename and the raw mime property', function () {
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

test('local image toArray returns the explicitly set mime type', function () {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'data');

    try {
        $array = Image::fromPath($path)->withMimeType('image/custom')->toArray();

        expect($array['mime'])->toBe('image/custom');
    } finally {
        @unlink($path);
    }
});

test('base64 document toArray reflects name and mime', function () {
    $doc = Document::fromString('hello world', 'text/plain')->as('greeting.txt');

    expect($doc->toArray())->toMatchArray([
        'type' => 'base64-document',
        'name' => 'greeting.txt',
        'mime' => 'text/plain',
    ]);
});

test('remote document toArray never issues an HTTP request', function () {
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

test('remote image toArray returns the explicitly set mime type without HTTP calls', function () {
    Http::preventStrayRequests();
    Http::fake();

    $array = Image::fromUrl('https://example.com/avatar.png')->withMimeType('image/png')->toArray();

    expect($array['mime'])->toBe('image/png');
    expect($array['name'])->toBe('avatar.png');

    Http::assertNothingSent();
});

test('remote document toArray handles urls without a path component', function () {
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

test('local image toArray does not touch the filesystem', function () {
    $path = '/tmp/this-file-definitely-does-not-exist-'.uniqid().'.png';

    $array = Image::fromPath($path)->toArray();

    expect($array)->toBe([
        'type' => 'local-image',
        'name' => basename($path),
        'path' => $path,
        'mime' => null,
    ]);
});

test('base64 image asDataUri uses correct mime and format', function () {
    $image = Image::fromBase64('abc123', 'image/png');

    expect($image->asDataUri())->toBe('data:image/png;base64,abc123');
});

test('base64 image asDataUri falls back to image/png', function () {
    $image = Image::fromBase64('abc123');

    expect($image->asDataUri())->toBe('data:image/png;base64,abc123');
});

test('base64 document asDataUri uses correct mime', function () {
    $doc = Document::fromString('hello world', 'text/plain')->as('greeting.txt');

    expect($doc->asDataUri())->toBe('data:text/plain;base64,'.base64_encode('hello world'));
});

test('base64 document asDataUri falls back to application/octet-stream', function () {
    $doc = Document::fromString('hello world');

    expect($doc->asDataUri())->toBe('data:application/octet-stream;base64,'.base64_encode('hello world'));
});

test('base64 audio asDataUri uses correct mime', function () {
    $audio = Audio::fromBase64('abc123', 'audio/mpeg');

    expect($audio->asDataUri())->toBe('data:audio/mpeg;base64,abc123');
});

test('base64 audio asDataUri falls back to audio/mpeg', function () {
    $audio = Audio::fromBase64('abc123');

    expect($audio->asDataUri())->toBe('data:audio/mpeg;base64,abc123');
});

test('local image asDataUri returns correct format', function () {
    $file = fakePngFile('local-image');
    $path = $file['path'];

    try {
        $image = Image::fromPath($path);
        $dataUri = $image->asDataUri();

        expect($dataUri)->toStartWith('data:image/png;base64,');
        expect($dataUri)->toContain('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    } finally {
        @unlink($path);
    }
});

test('local image asDataUri uses explicitly set mime type', function () {
    $file = fakePngFile('local-image');
    $path = $file['path'];

    try {
        $image = Image::fromPath($path)->withMimeType('image/webp');
        $dataUri = $image->asDataUri();

        expect($dataUri)->toStartWith('data:image/webp;base64,');
        expect($dataUri)->toContain('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    } finally {
        @unlink($path);
    }
});

test('stored image asDataUri returns correct format', function () {
    Storage::fake('images');
    Storage::disk('images')->put('photos/avatar.png', 'fake-image-content');

    $uri = Image::fromStorage('photos/avatar.png', 'images')->asDataUri();

    expect($uri)->toStartWith('data:image/png;base64,');
});

test('base64 image base64 method returns raw base64', function () {
    $image = Image::fromBase64('abc123', 'image/png');

    expect($image->base64())->toBe('abc123');
});

test('local image base64 method returns encoded content', function () {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'test-content');

    try {
        $image = Image::fromPath($path);

        expect($image->base64())->toBe(base64_encode('test-content'));
    } finally {
        @unlink($path);
    }
});

test('inline-capable files implement inline serialization contract', function () {
    Storage::fake('files');
    Storage::disk('files')->put('document.txt', 'stored document');
    Storage::disk('files')->put('image.png', 'stored image');
    Storage::disk('files')->put('audio.mp3', 'stored audio');

    expect(Image::fromBase64('abc123'))->toBeInstanceOf(InlineFile::class)
        ->and(Image::fromPath(__DIR__.'/../Fixtures/Images/red.png'))->toBeInstanceOf(InlineFile::class)
        ->and(Image::fromStorage('image.png', 'files'))->toBeInstanceOf(InlineFile::class)
        ->and(Document::fromString('hello world'))->toBeInstanceOf(InlineFile::class)
        ->and(Document::fromPath(__DIR__.'/../Fixtures/document.txt'))->toBeInstanceOf(InlineFile::class)
        ->and(Document::fromStorage('document.txt', 'files'))->toBeInstanceOf(InlineFile::class)
        ->and(Audio::fromBase64('abc123'))->toBeInstanceOf(InlineFile::class)
        ->and(Audio::fromPath(__DIR__.'/../Fixtures/audio.mp3'))->toBeInstanceOf(InlineFile::class)
        ->and(Audio::fromStorage('audio.mp3', 'files'))->toBeInstanceOf(InlineFile::class);
});

test('remote and provider files do not expose inline serialization helpers', function () {
    expect(Image::fromUrl('https://example.com/image.png'))->not->toBeInstanceOf(InlineFile::class)
        ->and(method_exists(Image::fromUrl('https://example.com/image.png'), 'asDataUri'))->toBeFalse()
        ->and(Image::fromId('file_123'))->not->toBeInstanceOf(InlineFile::class)
        ->and(method_exists(Image::fromId('file_123'), 'asDataUri'))->toBeFalse()
        ->and(Document::fromUrl('https://example.com/document.pdf'))->not->toBeInstanceOf(InlineFile::class)
        ->and(method_exists(Document::fromUrl('https://example.com/document.pdf'), 'asDataUri'))->toBeFalse()
        ->and(Document::fromId('file_456'))->not->toBeInstanceOf(InlineFile::class)
        ->and(method_exists(Document::fromId('file_456'), 'asDataUri'))->toBeFalse()
        ->and(Audio::fromUrl('https://example.com/audio.mp3'))->not->toBeInstanceOf(InlineFile::class)
        ->and(method_exists(Audio::fromUrl('https://example.com/audio.mp3'), 'asDataUri'))->toBeFalse();
});
