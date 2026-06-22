<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('get file sends correct request', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    $response = Files::get('file-abc123', provider: 'openai');

    expect($response->id)->toBe('file-abc123');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('put file sends multipart upload with user_data purpose', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'openai',
    );

    expect($response->id)->toBe('file-uploaded123');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/files'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
            && collect($request->data())->contains(fn ($field) => ($field['name'] ?? null) === 'purpose' && ($field['contents'] ?? null) === 'user_data')
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('delete file sends correct request', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123', 'deleted' => true]),
    ]);

    Files::delete('file-abc123', provider: 'openai');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});
