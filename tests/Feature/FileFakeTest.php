<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\FileResponse;

test('files can be faked', function () {
    Files::fake([
        'first-content',
        fn ($fileId) => "content-for-{$fileId}",
        new FileResponse('id', mimeType: 'application/json', content: 'third-content'),
    ]);

    $response = Files::get('file_1');
    expect($response->id)->toEqual('file_1')
        ->and($response->content)->toEqual('first-content');

    $response = Files::get('file_2');
    expect($response->id)->toEqual('file_2')
        ->and($response->content)->toEqual('content-for-file_2');

    $response = Files::get('file_3');
    expect($response->id)->toEqual('id')
        ->and($response->content)->toEqual('third-content');
});

test('files can be faked with no predefined responses', function () {
    Files::fake();

    $response = Files::get('file_1');
    expect($response->id)->toEqual('file_1')
        ->and($response->content)->toEqual('fake-content');

    $response = Files::get('file_2');
    expect($response->id)->toEqual('file_2')
        ->and($response->content)->toEqual('fake-content');
});

test('files can be faked with a closure', function () {
    Files::fake(fn ($fileId) => "content-for-{$fileId}");

    $response = Files::get('file_1');
    expect($response->id)->toEqual('file_1')
        ->and($response->content)->toEqual('content-for-file_1');

    $response = Files::get('file_2');
    expect($response->id)->toEqual('file_2')
        ->and($response->content)->toEqual('content-for-file_2');
});

test('files can prevent stray operations', function () {
    Files::fake()->preventStrayOperations();

    Files::get('file_1');
})->throws(RuntimeException::class);

test('can assert file was stored', function () {
    Files::fake();

    $id = Document::fromString('Hello, World!', 'text/plain')->as('document.txt')->put()->id;
    expect(Files::fakeId('document.txt'))->toEqual($id);

    Document::fromPath(__DIR__.'/../Fixtures/document.txt')->put();
    Document::fromUpload(new UploadedFile(__DIR__.'/../Fixtures/report.txt', 'report.txt'))->put();

    Files::assertStored(fn (StorableFile $file) => (string) $file === 'Hello, World!');

    Files::assertStored(fn (StorableFile $file) => trim((string) $file) === 'I am a local document.');
    Files::assertStored(fn (StorableFile $file) => $file->name() === 'document.txt');

    Files::assertStored(fn (StorableFile $file) => trim((string) $file) === 'I am an expense report.');
    Files::assertStored(fn (StorableFile $file) => $file->name() === 'report.txt');

    Files::assertStored(fn (StorableFile $file) => $file->mimeType() === 'text/plain');
    Files::assertNotStored(fn (StorableFile $file) => $file->mimeType() === 'application/json');
});

test('can assert no files were stored', function () {
    Files::fake();

    Files::assertNothingStored();
});

test('can override the filename when storing files from each document constructor', function () {
    Files::fake();

    Document::fromString('Hello, World!', 'text/plain')->put(name: 'custom-name.txt');
    Document::fromPath(__DIR__.'/../Fixtures/document.txt')->put(name: 'renamed-document.txt');
    Document::fromUpload(new UploadedFile(__DIR__.'/../Fixtures/report.txt', 'report.txt'))
        ->put(name: 'renamed-report.txt');

    Files::assertStored(fn (StorableFile $file) => $file->name() === 'custom-name.txt');
    Files::assertStored(fn (StorableFile $file) => $file->name() === 'renamed-document.txt');
    Files::assertStored(fn (StorableFile $file) => $file->name() === 'renamed-report.txt');
});

test('can override the filename when storing an existing storable file', function () {
    Files::fake();

    Files::put(Document::fromPath(__DIR__.'/../Fixtures/document.txt'), name: 'override.txt');

    Files::assertStored(fn (StorableFile $file) => $file->name() === 'override.txt');
});

test('can override the filename when storing files from a local path', function () {
    Files::fake();

    Files::putFromPath(__DIR__.'/../Fixtures/document.txt', name: 'from-path.txt');

    Files::assertStored(fn (StorableFile $file) => $file->name() === 'from-path.txt');
});

test('can override the filename when storing files from a storage disk', function () {
    Files::fake();

    Storage::fake('docs');
    Storage::disk('docs')->put('original.txt', 'contents');

    Files::putFromStorage('original.txt', disk: 'docs', name: 'from-storage.txt');

    Files::assertStored(fn (StorableFile $file) => $file->name() === 'from-storage.txt');
});

test('can override the mime type when storing files from each source', function () {
    Files::fake();

    Storage::fake('docs');
    Storage::disk('docs')->put('original.txt', 'contents');

    Files::put(Document::fromPath(__DIR__.'/../Fixtures/document.txt'), mimeType: 'application/x-local');
    Files::putFromPath(__DIR__.'/../Fixtures/document.txt', mimeType: 'application/x-path');
    Files::put(Document::fromStorage('original.txt', 'docs'), mimeType: 'application/x-storage');

    Files::assertStored(fn (StorableFile $file) => $file->mimeType() === 'application/x-local');
    Files::assertStored(fn (StorableFile $file) => $file->mimeType() === 'application/x-path');
    Files::assertStored(fn (StorableFile $file) => $file->mimeType() === 'application/x-storage');
    Files::assertNotStored(fn (StorableFile $file) => $file->mimeType() === 'text/plain');
});

test('can override the name and mime type when storing an existing storable file', function () {
    Files::fake();

    Files::put(Document::fromPath(__DIR__.'/../Fixtures/document.txt'), mimeType: 'application/json', name: 'renamed.txt');

    Files::assertStored(fn (StorableFile $file) => $file->name() === 'renamed.txt');
    Files::assertStored(fn (StorableFile $file) => $file->mimeType() === 'application/json');
    Files::assertNotStored(fn (StorableFile $file) => $file->mimeType() === 'text/plain');
});

test('can assert file was deleted', function () {
    Files::fake();

    Files::delete('file_123');

    Files::assertDeleted('file_123');
    Files::assertDeleted(fn ($id) => $id === 'file_123');
    Files::assertNotDeleted('file_456');
    Files::assertNotDeleted(fn ($id) => $id === 'file_456');
});

test('can assert no files were deleted', function () {
    Files::fake();

    Files::assertNothingDeleted();
});
