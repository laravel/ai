<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function () {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-gemini-key',
    ]]);
});

test('get file sends correct request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'files/abc123',
            'mimeType' => 'text/plain',
        ]),
    ]);

    $response = Files::get('abc123', provider: 'gemini');

    expect($response->id)->toBe('files/abc123');
    expect($response->mime)->toBe('text/plain');

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'v1beta/files/abc123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key');
    });
});

test('get file normalizes id with prefix', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'files/abc123',
            'mimeType' => 'application/pdf',
        ]),
    ]);

    Files::get('files/abc123', provider: 'gemini');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'v1beta/files/abc123')
            && ! str_contains($request->url(), 'files/files/');
    });
});

test('put file sends multipart upload', function () {
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

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/upload/v1beta/files')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key');
    });
});

test('delete file sends correct request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    Files::delete('abc123', provider: 'gemini');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), 'v1beta/files/abc123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key');
    });
});

test('delete file normalizes id with prefix', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
    ]);

    Files::delete('files/abc123', provider: 'gemini');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'v1beta/files/abc123')
            && ! str_contains($request->url(), 'files/files/');
    });
});

test('file gateway uses custom base url', function () {
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

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'custom.api.example.com/v1beta/files/abc123');
    });
});
