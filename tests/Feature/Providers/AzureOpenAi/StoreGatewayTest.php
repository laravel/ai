<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]]);
});

function azureProvider()
{
    return app(AiManager::class)->instance('azure');
}

function fakeAzureStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
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

test('get store sends correct request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    $store = Stores::get('vs-123', provider: 'azure');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store')
        ->and($store->fileCounts->completed)->toBe(5)
        ->and($store->fileCounts->pending)->toBe(1)
        ->and($store->fileCounts->failed)->toBe(0)
        ->and($store->ready)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
            && $request->hasHeader('api-key', 'test-key');
    });
});

test('get store parses description from metadata', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response([
            ...fakeAzureStoreResponse(),
            'metadata' => ['description' => 'A test store'],
        ]),
    ]);

    $store = Stores::get('vs-123', provider: 'azure');

    expect($store->description)->toBe('A test store');
});

test('create store sends correct request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    $store = Stores::create('Test Store', provider: 'azure');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores'
            && ($request->data()['name'] ?? null) === 'Test Store';
    });
});

test('create store maps description to metadata', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    Stores::create('Test Store', description: 'A test store', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && ! array_key_exists('description', $request->data())
            && ($request->data()['metadata'] ?? null) === ['description' => 'A test store'];
    });
});

test('create store includes file ids in request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    Stores::create('Test Store', fileIds: ['file-1', 'file-2'], provider: 'azure');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && ($request->data()['file_ids'] ?? null) === ['file-1', 'file-2'];
    });
});

test('create store includes expiration when provided', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    Stores::create('Expiring Store', expiresWhenIdleFor: days(7), provider: 'azure');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }

        $expires = $request->data()['expires_after'] ?? [];

        return ($expires['anchor'] ?? null) === 'last_active_at'
            && ($expires['days'] ?? null) === 7;
    });
});

test('add file sends correct request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(['id' => 'doc-456']),
    ]);

    $provider = azureProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789');

    expect($documentId)->toBe('doc-456');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123/files'
            && ($request->data()['file_id'] ?? null) === 'file-789';
    });
});

test('add file with metadata includes attributes', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(['id' => 'doc-456']),
    ]);

    $provider = azureProvider();
    $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789', ['company' => 'laravel']);

    Http::assertSent(function (Request $request) {
        return ($request->data()['attributes'] ?? null) === ['company' => 'laravel'];
    });
});

test('remove file sends correct request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(['deleted' => true]),
    ]);

    $provider = azureProvider();
    $result = $provider->storeGateway()->removeFile($provider, 'vs-123', 'doc-456');

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123/files/doc-456';
    });
});

test('delete store sends correct request', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(['deleted' => true]),
    ]);

    $result = Stores::delete('vs-123', provider: 'azure');

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
            && $request->hasHeader('api-key', 'test-key');
    });
});
