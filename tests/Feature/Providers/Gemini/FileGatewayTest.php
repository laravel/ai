<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-gemini-key',
    ]]);
});

test('get file sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'files/abc123',
            'mimeType' => 'text/plain',
        ]),
    ]);

    $response = Files::get('abc123', provider: 'gemini');

    expect($response->id)->toBe('files/abc123');
    expect($response->mime)->toBe('text/plain');

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_contains((string) $request->url(), 'v1beta/files/abc123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('get file normalizes id with prefix', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'files/abc123',
            'mimeType' => 'application/pdf',
        ]),
    ]);

    Files::get('files/abc123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'v1beta/files/abc123')
        && ! str_contains((string) $request->url(), 'files/files/'));
});

test('put file sends multipart upload', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'file' => [
                'name' => 'files/uploaded123',
            ],
        ]),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'gemini',
    );

    expect($response->id)->toBe('files/uploaded123');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), '/upload/v1beta/files')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('put file merges flat provider options into the upload body', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['mime_type' => 'image/png'])
        ->put(provider: 'gemini');

    $request = sentRequest();

    expect(multipartField($request, 'mime_type'))->toBe('image/png')
        ->and(multipartNestedField($request, 'file'))->toBe(['hello.txt']);
});

test('put file provider options override only the file metadata keys they specify', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['file' => ['display_name' => 'override.txt']])
        ->put(provider: 'gemini');

    expect(multipartNestedField(sentRequest(), 'file'))->toBe(['override.txt']);
});

test('put file provider options deep-merge into the file metadata without wiping the display name', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['file' => ['mime_type' => 'image/png']])
        ->put(provider: 'gemini');

    expect(multipartNestedField(sentRequest(), 'file'))->toBe(['hello.txt', 'image/png']);
});

test('delete file sends correct request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    Files::delete('abc123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), 'v1beta/files/abc123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('delete file normalizes id with prefix', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    Files::delete('files/abc123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'v1beta/files/abc123')
        && ! str_contains((string) $request->url(), 'files/files/'));
});

test('file gateway uses custom base url', function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-gemini-key',
        'url' => 'https://custom.api.example.com/v1beta',
    ]]);

    Http::fake([
        'custom.api.example.com/*' => Http::response([
            'name' => 'files/abc123',
            'mimeType' => 'text/plain',
        ]),
    ]);

    Files::get('abc123', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'custom.api.example.com/v1beta/files/abc123'));
});
