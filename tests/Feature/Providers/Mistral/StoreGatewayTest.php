<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

function fakeMistralLibraryResponse(string $id = 'lib-123', string $name = 'Test Store', int $nbDocuments = 0): array
{
    return [
        'id' => $id,
        'name' => $name,
        'description' => null,
        'chunk_size' => 1024,
        'nb_documents' => $nbDocuments,
        'total_size' => 0,
        'owner_type' => 'Workspace',
    ];
}

test('get store fetches the library', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->id)->toBe('lib-123')
        ->and($store->name)->toBe('Test Store')
        ->and($store->fileCounts->completed)->toBe(0)
        ->and($store->ready)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('get store derives file counts from document statuses', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents*' => Http::response([
            'data' => [
                ['id' => 'doc-1', 'process_status' => 'done'],
                ['id' => 'doc-2', 'process_status' => 'in_progress'],
                ['id' => 'doc-3', 'process_status' => 'error'],
            ],
        ]),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse(nbDocuments: 3)),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->fileCounts->completed)->toBe(1)
        ->and($store->fileCounts->pending)->toBe(1)
        ->and($store->fileCounts->failed)->toBe(1);
});

test('get store paginates document counts across multiple pages', function () {
    $firstPage = collect(range(1, 100))->map(fn (int $i) => [
        'id' => "doc-{$i}",
        'process_status' => 'done',
    ])->all();

    $secondPage = [
        ['id' => 'doc-101', 'process_status' => 'done'],
        ['id' => 'doc-102', 'process_status' => 'in_progress'],
        ['id' => 'doc-103', 'process_status' => 'error'],
    ];

    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents*' => function (Request $request) use ($firstPage, $secondPage) {
            $page = (int) $request->uri()->query()->get('page', 0);

            return Http::response(['data' => $page === 0 ? $firstPage : $secondPage]);
        },
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse(nbDocuments: 103)),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->fileCounts->completed)->toBe(101)
        ->and($store->fileCounts->pending)->toBe(1)
        ->and($store->fileCounts->failed)->toBe(1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents')
        && $request->uri()->query()->get('page') === '0'
        && $request->uri()->query()->get('page_size') === '100');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents')
        && $request->uri()->query()->get('page') === '1'
        && $request->uri()->query()->get('page_size') === '100');
});

test('create store posts name and description', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries' => Http::response(fakeMistralLibraryResponse()),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $store = Stores::create('Test Store', description: 'My documents', provider: 'mistral');

    expect($store->id)->toBe('lib-123');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries'
        && $request['name'] === 'Test Store'
        && $request['description'] === 'My documents');
});

test('create store posts a falsy description', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries' => Http::response(fakeMistralLibraryResponse()),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $store = Stores::create('Test Store', description: '0', provider: 'mistral');

    expect($store->id)->toBe('lib-123');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries'
        && $request['name'] === 'Test Store'
        && $request['description'] === '0');
});

test('create store with file ids throws', function () {
    Stores::create('Test Store', fileIds: ['file-1'], provider: 'mistral');
})->throws(RuntimeException::class, 'Mistral does not support attaching existing files');

test('add file by id throws', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries*' => Http::response(fakeMistralLibraryResponse()),
    ]);

    Stores::get('lib-123', provider: 'mistral')->add('file-123');
})->throws(RuntimeException::class, 'Mistral does not support adding existing files');

test('remove file deletes the document', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
        'api.mistral.ai/v1/libraries/lib-123/documents/doc-1' => Http::response([], 204),
    ]);

    $removed = Stores::get('lib-123', provider: 'mistral')->remove('doc-1');

    expect($removed)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123/documents/doc-1');
});

test('delete store deletes the library', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    expect(Stores::delete('lib-123', provider: 'mistral'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123');
});

test('adding a storable file uploads it directly to the library', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents' => Http::response([
            'id' => 'doc-1',
            'library_id' => 'lib-123',
            'name' => 'hello.txt',
            'process_status' => 'in_progress',
        ]),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $response = Stores::get('lib-123', provider: 'mistral')
        ->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

    expect($response->id)->toBe('doc-1')
        ->and($response->fileId)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123/documents'
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && ($part['filename'] ?? null) === 'hello.txt');
    });

    // No global /v1/files upload happened...
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v1/files'));
});
