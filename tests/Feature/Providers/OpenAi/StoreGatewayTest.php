<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\StoreDocumentStatus;
use Laravel\Ai\Responses\Data\StoreDocument;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function openAiProvider()
{
    return app(AiManager::class)->instance('openai');
}

function fakeOpenAiStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'completed',
        'file_counts' => [
            'completed' => 5,
            'in_progress' => 1,
            'failed' => 0,
        ],
    ];
}

function fakeOpenAiStoreFile(string $id, string $status = 'completed', ?array $attributes = null): array
{
    return [
        'id' => $id,
        'object' => 'vector_store.file',
        'vector_store_id' => 'vs-123',
        'status' => $status,
        'attributes' => $attributes,
    ];
}

test('get store sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    $store = Stores::get('vs-123', provider: 'openai');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store')
        ->and($store->fileCounts->completed)->toBe(5)
        ->and($store->fileCounts->pending)->toBe(1)
        ->and($store->fileCounts->failed)->toBe(0)
        ->and($store->ready)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('create store sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    $store = Stores::create('Test Store', provider: 'openai');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.openai.com/v1/vector_stores'
        && ($request->data()['name'] ?? null) === 'Test Store');
});

test('create store maps description to metadata', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Test Store', description: 'A test store', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ! array_key_exists('description', $request->data())
        && ($request->data()['metadata'] ?? null) === ['description' => 'A test store']);
});

test('create store includes file ids in request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Test Store', fileIds: ['file-1', 'file-2'], provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ($request->data()['file_ids'] ?? null) === ['file-1', 'file-2']);
});

test('create store includes expiration when provided', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Expiring Store', expiresWhenIdleFor: days(7), provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        $expires = $request->data()['expires_after'] ?? [];

        return ($expires['anchor'] ?? null) === 'last_active_at'
            && ($expires['days'] ?? null) === 7;
    });
});

test('add file sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'doc-456']),
    ]);

    $provider = openAiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789');

    expect($documentId)->toBe('doc-456');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files'
        && ($request->data()['file_id'] ?? null) === 'file-789');
});

test('add file with metadata includes attributes', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'doc-456']),
    ]);

    $provider = openAiProvider();
    $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789', ['company' => 'laravel']);

    Http::assertSent(fn (Request $request): bool => ($request->data()['attributes'] ?? null) === ['company' => 'laravel']);
});

test('remove file sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['deleted' => true]),
    ]);

    $provider = openAiProvider();
    $result = $provider->storeGateway()->removeFile($provider, 'vs-123', 'doc-456');

    expect($result)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files/doc-456');
});

test('delete store sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['deleted' => true]),
    ]);

    $result = Stores::delete('vs-123', provider: 'openai');

    expect($result)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('remove file encodes the store and document ids as single path segments', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['deleted' => true]),
    ]);

    $provider = openAiProvider();
    $provider->storeGateway()->removeFile($provider, 'vs-123#', '../../files/file-456');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123%23/files/..%2F..%2Ffiles%2Ffile-456');
});

test('delete store encodes the id as a single path segment', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['deleted' => true]),
    ]);

    Stores::delete('../files/file-456', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/..%2Ffiles%2Ffile-456');
});

test('list files returns the documents in the store', function (): void {
    Http::fake([
        'api.openai.com/v1/vector_stores/vs-123/files*' => Http::response([
            'object' => 'list',
            'data' => [
                fakeOpenAiStoreFile('file-1', 'completed', ['company' => 'laravel']),
                fakeOpenAiStoreFile('file-2', 'in_progress'),
                fakeOpenAiStoreFile('file-3', 'failed'),
                fakeOpenAiStoreFile('file-4', 'cancelled'),
            ],
            'first_id' => 'file-1',
            'last_id' => 'file-4',
            'has_more' => false,
        ]),
        'api.openai.com/*' => Http::response(fakeOpenAiStoreResponse()),
    ]);

    $documents = Stores::get('vs-123', provider: 'openai')->documents()->all();

    expect($documents)->toHaveCount(4)
        ->and($documents[0])->toBeInstanceOf(StoreDocument::class)
        ->and($documents[0]->id)->toBe('file-1')
        ->and($documents[0]->fileId)->toBe('file-1')
        ->and($documents[0]->status)->toBe(StoreDocumentStatus::Completed)
        ->and($documents[0]->completed())->toBeTrue()
        ->and($documents[0]->metadata)->toBe(['company' => 'laravel'])
        ->and($documents[1]->status)->toBe(StoreDocumentStatus::Pending)
        ->and($documents[1]->metadata)->toBe([])
        ->and($documents[2]->status)->toBe(StoreDocumentStatus::Failed)
        ->and($documents[3]->status)->toBe(StoreDocumentStatus::Failed);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files?limit=100'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('list files follows the cursor to the next page', function (): void {
    Http::fake([
        'api.openai.com/v1/vector_stores/vs-123/files*' => Http::sequence()
            ->push([
                'object' => 'list',
                'data' => [fakeOpenAiStoreFile('file-1'), fakeOpenAiStoreFile('file-2')],
                'last_id' => 'file-2',
                'has_more' => true,
            ])
            ->push([
                'object' => 'list',
                'data' => [fakeOpenAiStoreFile('file-3')],
                'last_id' => 'file-3',
                'has_more' => false,
            ]),
    ]);

    $provider = openAiProvider();

    $ids = $provider->storeGateway()->listFiles($provider, 'vs-123')->pluck('id')->all();

    expect($ids)->toBe(['file-1', 'file-2', 'file-3']);

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files?limit=100&after=file-2');
});

test('list files only fetches the pages that are iterated', function (): void {
    Http::fake([
        'api.openai.com/v1/vector_stores/vs-123/files*' => Http::response([
            'object' => 'list',
            'data' => [fakeOpenAiStoreFile('file-1'), fakeOpenAiStoreFile('file-2')],
            'last_id' => 'file-2',
            'has_more' => true,
        ]),
    ]);

    $provider = openAiProvider();

    $files = $provider->storeGateway()->listFiles($provider, 'vs-123');

    Http::assertNothingSent();

    expect($files->first()->id)->toBe('file-1');

    Http::assertSentCount(1);
});

test('list files returns an empty collection for an empty store', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false]),
    ]);

    $provider = openAiProvider();

    expect($provider->storeGateway()->listFiles($provider, 'vs-123')->all())->toBe([]);
});

test('list files encodes the store id as a single path segment', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false]),
    ]);

    $provider = openAiProvider();
    $provider->storeGateway()->listFiles($provider, '../files')->all();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/vector_stores/..%2Ffiles/files?limit=100');
});
