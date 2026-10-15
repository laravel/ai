<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\FileAddedToStore;
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
        ->and($store->fileCounts->failed)->toBe(1)
        ->and($store->ready)->toBeFalse();
});

test('get store stops paginating when the api reports no more pages', function () {
    $fullPage = collect(range(1, 100))->map(fn (int $i) => [
        'id' => "doc-{$i}",
        'process_status' => 'done',
    ])->all();

    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents*' => Http::response([
            'pagination' => ['total_items' => 100, 'total_pages' => 1, 'current_page' => 0, 'page_size' => 100, 'has_more' => false],
            'data' => $fullPage,
        ]),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse(nbDocuments: 100)),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->fileCounts->completed)->toBe(100);

    Http::assertSentCount(2);
});

test('get store keeps paginating while the api reports more pages', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents*' => function (Request $request) {
            $page = (int) ($request->data()['page'] ?? 0);

            return Http::response([
                'pagination' => ['total_items' => 2, 'total_pages' => 2, 'current_page' => $page, 'page_size' => 100, 'has_more' => $page === 0],
                'data' => [['id' => "doc-{$page}", 'process_status' => $page === 0 ? 'done' : 'in_progress']],
            ]);
        },
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse(nbDocuments: 2)),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->fileCounts->completed)->toBe(1)
        ->and($store->fileCounts->pending)->toBe(1);

    Http::assertSentCount(3);
});

test('get store maps every document process status', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents*' => Http::response([
            'data' => [
                ['id' => 'doc-1', 'process_status' => 'done'],
                ['id' => 'doc-2', 'process_status' => 'noop'],
                ['id' => 'doc-3', 'process_status' => 'self_managed'],
                ['id' => 'doc-4', 'process_status' => 'todo'],
                ['id' => 'doc-5', 'process_status' => 'in_progress'],
                ['id' => 'doc-6', 'process_status' => 'waiting_for_capacity'],
                ['id' => 'doc-7', 'process_status' => 'error'],
                ['id' => 'doc-8', 'process_status' => 'missing_content'],
            ],
        ]),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse(nbDocuments: 8)),
    ]);

    $store = Stores::get('lib-123', provider: 'mistral');

    expect($store->fileCounts->completed)->toBe(3)
        ->and($store->fileCounts->pending)->toBe(3)
        ->and($store->fileCounts->failed)->toBe(2);
});

test('create store posts name and description', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $store = Stores::create('Test Store', description: 'My documents', provider: 'mistral');

    expect($store->id)->toBe('lib-123')
        ->and($store->name)->toBe('Test Store');

    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries'
        && $request['name'] === 'Test Store'
        && $request['description'] === 'My documents');
});

test('create store posts a falsy description', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $store = Stores::create('Test Store', description: '0', provider: 'mistral');

    expect($store->id)->toBe('lib-123');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries'
        && $request['name'] === 'Test Store'
        && $request['description'] === '0');
});

test('create store ignores unsupported idle expiration', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries' => Http::response(fakeMistralLibraryResponse()),
    ]);

    Stores::create('Test Store', expiresWhenIdleFor: new DateInterval('P7D'), provider: 'mistral');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries'
        && ! array_key_exists('expires_after', $request->data())
        && ! array_key_exists('expires_when_idle_for', $request->data()));
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

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v1/files'));
});

test('adding a storable file with metadata updates the document attributes', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents/doc-1' => Http::response([
            'id' => 'doc-1',
            'attributes' => ['team' => 'laravel'],
        ]),
        'api.mistral.ai/v1/libraries/lib-123/documents' => Http::response([
            'id' => 'doc-1',
            'process_status' => 'in_progress',
        ], 201),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $response = Stores::get('lib-123', provider: 'mistral')
        ->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'), metadata: ['team' => 'laravel', 'year' => 2026]);

    expect($response->id)->toBe('doc-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/libraries/lib-123/documents')
        && collect($request->data())->pluck('name')->all() === ['file']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123/documents/doc-1'
        && $request['attributes'] === ['team' => 'laravel', 'year' => 2026]);
});

test('adding a storable file without metadata does not update the document', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents' => Http::response(['id' => 'doc-1'], 201),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    Stores::get('lib-123', provider: 'mistral')
        ->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
});

test('adding an uploaded file uploads it directly to the library', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents' => Http::response(['id' => 'doc-1'], 201),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    $response = Stores::get('lib-123', provider: 'mistral')
        ->add(UploadedFile::fake()->createWithContent('report.txt', 'Quarterly report'));

    expect($response->id)->toBe('doc-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123/documents'
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'file'
            && ($part['filename'] ?? null) === 'report.txt'
            && $part['contents'] === 'Quarterly report'));
});

test('adding a storable file dispatches the store file events', function () {
    Event::fake([AddingFileToStore::class, FileAddedToStore::class]);

    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123/documents' => Http::response(['id' => 'doc-1'], 201),
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
    ]);

    Stores::get('lib-123', provider: 'mistral')
        ->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

    Event::assertDispatched(fn (AddingFileToStore $event) => $event->storeId === 'lib-123'
        && $event->fileId === null);

    Event::assertDispatched(fn (FileAddedToStore $event) => $event->storeId === 'lib-123'
        && $event->fileId === null
        && $event->documentId === 'doc-1');
});

test('removing a document with file deletion does not call the files api', function () {
    Http::fake([
        'api.mistral.ai/v1/libraries/lib-123' => Http::response(fakeMistralLibraryResponse()),
        'api.mistral.ai/v1/libraries/lib-123/documents/doc-1' => Http::response([], 204),
    ]);

    $removed = Stores::get('lib-123', provider: 'mistral')->remove('doc-1', deleteFile: true);

    expect($removed)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mistral.ai/v1/libraries/lib-123/documents/doc-1');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/files'));
});
