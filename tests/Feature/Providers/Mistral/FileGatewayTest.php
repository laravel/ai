<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('put file uploads via multipart with ocr purpose', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response(['id' => 'file-123', 'object' => 'file']),
    ]);

    $response = Files::put(
        Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'),
        provider: 'mistral',
    );

    expect($response->id)->toBe('file-123');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.mistral.ai/v1/files'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'purpose' && $part['contents'] === 'ocr')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && ($part['filename'] ?? null) === 'hello.txt');
    });
});

test('get file returns id and mime type', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'id' => 'file-123',
            'object' => 'file',
            'filename' => 'hello.txt',
            'mimetype' => 'text/plain',
        ]),
    ]);

    $response = Files::get('file-123', provider: 'mistral');

    expect($response->id)->toBe('file-123')
        ->and($response->mimeType())->toBe('text/plain');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.mistral.ai/v1/files/file-123');
});

test('delete file sends delete request', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response(['id' => 'file-123', 'deleted' => true]),
    ]);

    Files::delete('file-123', provider: 'mistral');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mistral.ai/v1/files/file-123');
});
