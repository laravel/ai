<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\LocalVideo;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;

$fixture = __DIR__.'/../../Fixtures/document.txt';

test('local files stream their contents from disk', function (string $class) use ($fixture): void {
    $stream = (new $class($fixture))->stream();

    expect($stream->getContents())->toBe("I am a local document.\n");

    $stream->close();
})->with([LocalAudio::class, LocalDocument::class, LocalImage::class, LocalVideo::class]);

test('local files throw when streaming a missing path', function (string $class): void {
    expect(fn () => (new $class('/missing/file.txt'))->stream())
        ->toThrow(RuntimeException::class, 'File does not exist at path [/missing/file.txt]');
})->with([LocalAudio::class, LocalDocument::class, LocalImage::class, LocalVideo::class]);

test('stored files stream their contents from the configured disk', function (string $class): void {
    Storage::fake('uploads');
    Storage::disk('uploads')->put('file.txt', 'I am a stored file.');

    $stream = (new $class('file.txt', 'uploads'))->stream();

    expect($stream->getContents())->toBe('I am a stored file.');

    $stream->close();
})->with([StoredAudio::class, StoredDocument::class, StoredImage::class, StoredVideo::class]);

test('stored files throw when streaming a missing path', function (string $class): void {
    Storage::fake('uploads');

    expect(fn () => (new $class('missing.txt', 'uploads'))->stream())
        ->toThrow(RuntimeException::class, 'File [missing.txt] does not exist on disk [uploads].');
})->with([StoredAudio::class, StoredDocument::class, StoredImage::class, StoredVideo::class]);
