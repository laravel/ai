<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Files\Document;
use RuntimeException;

beforeEach(function () {
    $this->provider = 'anthropic';
});

test('can store files', function () {
    Event::fake();

    $response = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $this->provider
    );

    expect($response->id)->not->toBeEmpty();

    Event::assertDispatched(StoringFile::class);
    Event::assertDispatched(FileStored::class);

    Document::fromId($response->id)->delete(provider: $this->provider);

    Event::assertDispatched(FileDeleted::class);
});

test('can store files from local paths', function () {
    $response = Document::fromPath(__DIR__.'/files/document.txt')->put(
        name: 'document.txt', provider: $this->provider,
    );

    expect($response->id)->not->toBeEmpty();

    Document::fromId($response->id)->delete(provider: $this->provider);
});

test('can store files from storage paths', function () {
    Storage::disk('local')->put('document.txt', 'Hello, World!');

    $response = Document::fromStorage('document.txt', disk: 'local')->put(
        provider: $this->provider,
    );

    expect($response->id)->not->toBeEmpty();

    Document::fromId($response->id)->delete(provider: $this->provider);
});

test('can store files from remote paths', function () {
    $stored = Document::fromUrl(
        'https://raw.githubusercontent.com/laravel/laravel/refs/heads/12.x/README.md'
    )->put(
        provider: $this->provider,
    );

    expect($stored->id)->not->toBeEmpty();

    $response = Document::fromId($stored->id)->get(provider: $this->provider);

    expect($response->mime)->toEqual('text/plain');

    Document::fromId($response->id)->delete(provider: $this->provider);
});

test('exception is thrown if stored file does not exist', function () {
    $response = Document::fromStorage('missing-document.pdf', disk: 'local')->put(
        provider: $this->provider,
    );
})->throws(RuntimeException::class);

test('can get files', function () {
    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $this->provider
    );

    $response = Document::fromId($stored->id)->get(provider: $this->provider);

    expect($response->id)->toEqual($stored->id);
    expect($response->mime)->toEqual('text/plain');

    Document::fromId($response->id)->delete(provider: $this->provider);
});

test('can delete files', function () {
    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt', provider: $this->provider
    );

    Document::fromId($stored->id)->delete(provider: $this->provider);

    Document::fromId($stored->id)->get(provider: $this->provider);
})->throws(RequestException::class);
