<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Files\Document;

test('can store files', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    Event::fake();

    $response = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $provider
    );

    expect($response->id)->not->toBeEmpty();

    Event::assertDispatched(StoringFile::class);
    Event::assertDispatched(FileStored::class);

    Document::fromId($response->id)->delete(provider: $provider);

    Event::assertDispatched(FileDeleted::class);
})->with('file-providers');

test('can store files from local paths', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $response = Document::fromPath(__DIR__.'/../Fixtures/document.txt')->put(
        name: 'document.txt', provider: $provider,
    );

    expect($response->id)->not->toBeEmpty();

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('can store files from storage paths', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    Storage::disk('local')->put('document.txt', 'Hello, World!');

    $response = Document::fromStorage('document.txt', disk: 'local')->put(
        provider: $provider,
    );

    expect($response->id)->not->toBeEmpty();

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('can store files from remote paths', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $stored = Document::fromUrl(
        'https://raw.githubusercontent.com/laravel/laravel/refs/heads/12.x/README.md'
    )->put(
        provider: $provider,
    );

    expect($stored->id)->not->toBeEmpty();

    $response = Document::fromId($stored->id)->get(provider: $provider);

    // Not every provider returns the MIME type when fetching a file.
    expect($response->mime)->toBeIn(['text/plain', null]);

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('exception is thrown if stored file does not exist', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    Document::fromStorage('missing-document.pdf', disk: 'local')->put(
        provider: $provider,
    );
})->with('file-providers')->throws(RuntimeException::class);

test('can get files', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $provider
    );

    $response = Document::fromId($stored->id)->get(provider: $provider);

    // Not every provider returns the MIME type when fetching a file.
    expect($response->id)->toEqual($stored->id)
        ->and($response->mime)->toBeIn(['text/plain', null]);

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('can delete files', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $provider
    );

    Document::fromId($stored->id)->delete(provider: $provider);

    Document::fromId($stored->id)->get(provider: $provider);
})->with('file-providers')->throws(RequestException::class);
