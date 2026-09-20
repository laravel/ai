<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function (): void {
    config(['ai.providers.openrouter.key' => 'test-key']);
});

test('get file sends correct request and exposes the mime type', function (): void {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'id' => 'or_file_abc123',
            'mime_type' => 'text/csv',
        ]),
    ]);

    $response = Files::get('or_file_abc123', provider: 'openrouter');

    expect($response->id)->toBe('or_file_abc123')
        ->and($response->mimeType())->toBe('text/csv');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('put file sends a multipart upload without a purpose', function (): void {
    Http::fake([
        'openrouter.ai/*' => Http::response(['id' => 'or_file_uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'openrouter',
    );

    expect($response->id)->toBe('or_file_uploaded123');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://openrouter.ai/api/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and(multipartField($request, 'purpose'))->toBeNull()
        ->and($request->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue();
});

test('provider options are resolved with the openrouter key', function (): void {
    Http::fake([
        'openrouter.ai/*' => Http::response(['id' => 'or_file_uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
            Lab::OpenRouter => ['workspace_id' => 'ws_123'],
            default => [],
        })
        ->put(provider: 'openrouter');

    expect(multipartField(sentRequest(), 'workspace_id'))->toBe('ws_123');
});

test('delete file sends correct request', function (): void {
    Http::fake([
        'openrouter.ai/*' => Http::response(['id' => 'or_file_abc123', 'deleted' => true]),
    ]);

    Files::delete('or_file_abc123', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});
