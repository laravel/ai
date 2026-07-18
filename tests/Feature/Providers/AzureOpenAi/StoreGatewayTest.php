<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;

beforeEach(function (): void {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]]);
});

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

test('get store sends request to the v1 endpoint with the api-key header', function (): void {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    expect(Stores::get('vs-123', provider: 'azure')->id)->toBe('vs-123');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
        && $request->hasHeader('api-key', 'test-key'));
});

test('create store sends request to the v1 endpoint with the api-key header', function (): void {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    expect(Stores::create('Test Store', provider: 'azure')->id)->toBe('vs-123');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores'
        && $request->hasHeader('api-key', 'test-key'));
});

test('file search metadata filters throw an exception', function (): void {
    $search = new FileSearch(['vs-123'], where: ['company' => 'laravel']);

    expect(fn () => Ai::storeProvider('azure')->fileSearchToolOptions($search))
        ->toThrow(InvalidArgumentException::class, 'Azure OpenAI does not support file search metadata filters.');
});

test('file search without filters returns vector store ids', function (): void {
    $search = new FileSearch(['vs-123']);

    expect(Ai::storeProvider('azure')->fileSearchToolOptions($search))
        ->toBe(['vector_store_ids' => ['vs-123']]);
});
