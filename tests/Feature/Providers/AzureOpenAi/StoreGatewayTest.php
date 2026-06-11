<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Stores;

beforeEach(function () {
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

test('get store sends request to the v1 endpoint with the api-key header', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    expect(Stores::get('vs-123', provider: 'azure')->id)->toBe('vs-123');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
        && $request->hasHeader('api-key', 'test-key'));
});

test('create store sends request to the v1 endpoint with the api-key header', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(fakeAzureStoreResponse()),
    ]);

    expect(Stores::create('Test Store', provider: 'azure')->id)->toBe('vs-123');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores'
        && $request->hasHeader('api-key', 'test-key'));
});
