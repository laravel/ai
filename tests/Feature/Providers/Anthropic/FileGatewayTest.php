<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function () {
    config(['ai.providers.anthropic' => [
        ...config('ai.providers.anthropic'),
        'key' => 'test-key',
    ]]);
});

test('get file sends correct request', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-abc123', 'mime_type' => 'text/plain']),
    ]);

    $response = Files::get('file-abc123', provider: 'anthropic');

    expect($response->id)->toBe('file-abc123');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});

test('put file sends multipart upload', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'anthropic',
    );

    expect($response->id)->toBe('file-uploaded123');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.anthropic.com/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and($request->hasHeader('x-api-key', 'test-key'))->toBeTrue();
});

test('delete file sends correct request', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    Files::delete('file-abc123', provider: 'anthropic');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});
