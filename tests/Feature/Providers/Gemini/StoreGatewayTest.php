<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Stores;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-gemini-key',
    ]]);
});

function geminiProvider()
{
    return app(AiManager::class)->instance('gemini');
}

function fakeStoreResponse(string $name = 'fileSearchStores/store123', string $displayName = 'Test Store'): array
{
    return [
        'name' => $name,
        'displayName' => $displayName,
        'activeDocumentsCount' => 5,
        'pendingDocumentsCount' => 1,
        'failedDocumentsCount' => 0,
    ];
}

test('get store sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
    ]);

    $store = Stores::get('store123', provider: 'gemini');

    expect($store->id)->toBe('fileSearchStores/store123');
    expect($store->name)->toBe('Test Store');
    expect($store->fileCounts->completed)->toBe(5);
    expect($store->fileCounts->pending)->toBe(1);
    expect($store->fileCounts->failed)->toBe(0);
    expect($store->ready)->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('get store normalizes id with prefix', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
    ]);

    Stores::get('fileSearchStores/store123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
        && ! str_contains((string) $request->url(), 'fileSearchStores/fileSearchStores/'));
});

test('create store sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
    ]);

    $store = Stores::create('Test Store', provider: 'gemini');

    expect($store->id)->toBe('fileSearchStores/store123');
    expect($store->name)->toBe('Test Store');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), 'v1beta/fileSearchStores')
        && ($request->data()['displayName'] ?? null) === 'Test Store');
});

test('create store with file ids adds files', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response(['name' => 'fileSearchStores/store123']),
            Http::response(fakeStoreResponse()),
            Http::response(['name' => 'fileSearchStores/store123/documents/doc1']),
            Http::response(['name' => 'fileSearchStores/store123/documents/doc2']),
        ]),
    ]);

    Stores::create('Test Store', fileIds: ['files/file1', 'files/file2'], provider: 'gemini');

    Http::assertSentCount(4);
});

test('add file sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'fileSearchStores/store123/documents/doc456',
        ]),
    ]);

    $provider = geminiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

    expect($documentId)->toBe('doc456');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), 'fileSearchStores/store123:importFile')
        && ($request->data()['fileName'] ?? null) === 'files/file789');
});

test('add file with metadata formats correctly', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'fileSearchStores/store123/documents/doc456',
        ]),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->addFile($provider, 'store123', 'file789', [
        'company' => 'laravel',
        'priority' => 5,
        'tags' => ['php', 'framework'],
    ]);

    Http::assertSent(function ($request): bool {
        $metadata = $request->data()['customMetadata'] ?? [];

        $string = collect($metadata)->firstWhere('key', 'company');
        $numeric = collect($metadata)->firstWhere('key', 'priority');
        $list = collect($metadata)->firstWhere('key', 'tags');

        return $string['stringValue'] === 'laravel'
            && $numeric['numericValue'] === 5
            && $list['stringListValue']['values'] === ['php', 'framework'];
    });
});

test('remove file sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    $provider = geminiProvider();
    $result = $provider->storeGateway()->removeFile($provider, 'store123', 'doc456');

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file normalizes document id with files prefix', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'files/doc456');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file normalizes document id with documents prefix', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'documents/doc456');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file accepts full document path', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'fileSearchStores/store123/documents/doc456');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456')
        && ! str_contains((string) $request->url(), 'fileSearchStores/store123/documents/fileSearchStores/'));
});

test('delete store sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    $result = Stores::delete('store123', provider: 'gemini');

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('store gateway uses custom base url', function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-gemini-key',
        'url' => 'https://custom.api.example.com/v1beta',
    ]]);

    Http::fake([
        'custom.api.example.com/*' => Http::response(fakeStoreResponse()),
    ]);

    Stores::get('store123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'custom.api.example.com/v1beta/fileSearchStores/store123'));
});
