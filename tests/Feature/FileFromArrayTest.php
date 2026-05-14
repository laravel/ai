<?php

use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

dataset('attachment types', [
    'base64-image' => [fn () => (new Base64Image('aGVsbG8=', 'image/png'))->as('hi.png'), Base64Image::class, ['base64' => 'aGVsbG8=', 'mime' => 'image/png']],
    'local-image' => [fn () => (new LocalImage('/tmp/a.png', 'image/png'))->as('a.png'), LocalImage::class, ['path' => '/tmp/a.png', 'mime' => 'image/png']],
    'stored-image' => [fn () => (new StoredImage('photos/a.png', 'local'))->as('a.png'), StoredImage::class, ['path' => 'photos/a.png', 'disk' => 'local']],
    'remote-image' => [fn () => (new RemoteImage('https://x/y.png', 'image/png'))->as('y.png'), RemoteImage::class, ['url' => 'https://x/y.png', 'mime' => 'image/png']],
    'provider-image' => [fn () => (new ProviderImage('file_img_1'))->as('img.png'), ProviderImage::class, ['id' => 'file_img_1']],
    'base64-document' => [fn () => (new Base64Document('aGVsbG8=', 'application/pdf'))->as('a.pdf'), Base64Document::class, ['base64' => 'aGVsbG8=', 'mime' => 'application/pdf']],
    'local-document' => [fn () => (new LocalDocument('/tmp/a.pdf', 'application/pdf'))->as('a.pdf'), LocalDocument::class, ['path' => '/tmp/a.pdf', 'mime' => 'application/pdf']],
    'stored-document' => [fn () => (new StoredDocument('docs/a.pdf', 'local'))->as('a.pdf'), StoredDocument::class, ['path' => 'docs/a.pdf', 'disk' => 'local']],
    'remote-document' => [fn () => (new RemoteDocument('https://x/y.pdf', 'application/pdf'))->as('y.pdf'), RemoteDocument::class, ['url' => 'https://x/y.pdf', 'mime' => 'application/pdf']],
    'provider-document' => [fn () => (new ProviderDocument('file_doc_1'))->as('doc.pdf'), ProviderDocument::class, ['id' => 'file_doc_1']],
    'base64-audio' => [fn () => (new Base64Audio('aGVsbG8=', 'audio/mpeg'))->as('a.mp3'), Base64Audio::class, ['base64' => 'aGVsbG8=', 'mime' => 'audio/mpeg']],
    'local-audio' => [fn () => (new LocalAudio('/tmp/a.mp3', 'audio/mpeg'))->as('a.mp3'), LocalAudio::class, ['path' => '/tmp/a.mp3', 'mime' => 'audio/mpeg']],
    'stored-audio' => [fn () => (new StoredAudio('clips/a.mp3', 'local'))->as('a.mp3'), StoredAudio::class, ['path' => 'clips/a.mp3', 'disk' => 'local']],
    'remote-audio' => [fn () => (new RemoteAudio('https://x/y.mp3', 'audio/mpeg'))->as('y.mp3'), RemoteAudio::class, ['url' => 'https://x/y.mp3', 'mime' => 'audio/mpeg']],
]);

dataset('malformed attachment types', [
    'base64-image' => [['type' => 'base64-image'], 'base64'],
    'local-image' => [['type' => 'local-image'], 'path'],
    'stored-image' => [['type' => 'stored-image'], 'path'],
    'remote-image' => [['type' => 'remote-image'], 'url'],
    'provider-image' => [['type' => 'provider-image'], 'id'],
    'base64-document' => [['type' => 'base64-document'], 'base64'],
    'local-document' => [['type' => 'local-document'], 'path'],
    'stored-document' => [['type' => 'stored-document'], 'path'],
    'remote-document' => [['type' => 'remote-document'], 'url'],
    'provider-document' => [['type' => 'provider-document'], 'id'],
    'base64-audio' => [['type' => 'base64-audio'], 'base64'],
    'local-audio' => [['type' => 'local-audio'], 'path'],
    'stored-audio' => [['type' => 'stored-audio'], 'path'],
    'remote-audio' => [['type' => 'remote-audio'], 'url'],
]);

test('File::fromArray round-trips attachment types', function (Closure $factory, string $class, array $properties) {
    $original = $factory();

    $rehydrated = File::fromArray($original->toArray());

    expect($rehydrated)->toBeInstanceOf($class)
        ->and($rehydrated->name())->toBe($original->name());

    foreach ($properties as $property => $value) {
        expect($rehydrated->{$property})->toBe($value);
    }
})->with('attachment types');

test('File::fromArray returns null for an unknown type', function () {
    expect(File::fromArray(['type' => 'video']))->toBeNull()
        ->and(File::fromArray([]))->toBeNull();
});

test('File::fromArray throws for malformed known attachment types', function (array $data, string $field) {
    expect(fn () => File::fromArray($data))
        ->toThrow(InvalidArgumentException::class, "[{$field}] is missing or invalid");
})->with('malformed attachment types');

test('File::fromArray restores the name even when toArray emits null', function () {
    $rehydrated = File::fromArray([
        'type' => 'remote-image',
        'url' => 'https://example.com/x.png',
        'mime' => 'image/png',
        'name' => null,
    ]);

    expect($rehydrated)->toBeInstanceOf(RemoteImage::class)
        ->and($rehydrated->name)->toBeNull();
});
