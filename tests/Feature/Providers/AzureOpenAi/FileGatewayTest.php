<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Document;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]]);
});

test('put file uploads to the v1 endpoint with the assistants purpose', function () {
    Http::fake([
        'test-resource.openai.azure.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'azure',
    );

    expect($response->id)->toBe('file-uploaded123');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/files'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
        && collect($request->data())->contains(fn ($field) => ($field['name'] ?? null) === 'purpose' && ($field['contents'] ?? null) === 'assistants')
        && $request->hasHeader('api-key', 'test-key'));
});
