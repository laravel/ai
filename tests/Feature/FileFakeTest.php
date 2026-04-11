<?php

use Illuminate\Http\UploadedFile;
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

    Document::fromPath(__DIR__.'/files/document.txt')->put();
    Document::fromUpload(new UploadedFile(__DIR__.'/files/report.txt', 'report.txt'))->put();

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
