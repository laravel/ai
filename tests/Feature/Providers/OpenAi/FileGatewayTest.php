<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('get file sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    $response = Files::get('file-abc123', provider: 'openai');

    expect($response->id)->toBe('file-abc123');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('put file sends multipart upload with user_data purpose', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'openai',
    );

    expect($response->id)->toBe('file-uploaded123');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.openai.com/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and(multipartField($request, 'purpose'))->toBe('user_data')
        ->and($request->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue();
});

test('put file allows overriding the purpose via provider options', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['purpose' => 'fine-tune'])
        ->put(provider: 'openai');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.openai.com/v1/files')
        ->and(multipartField($request, 'purpose'))->toBe('fine-tune');
});

test('put file resolves provider options from a closure scoped to the provider', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
            Lab::OpenAI => ['purpose' => 'assistants'],
            default => [],
        })
        ->put(provider: 'openai');

    expect(multipartField(sentRequest(), 'purpose'))->toBe('assistants');
});

test('delete file sends correct request', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123', 'deleted' => true]),
    ]);

    Files::delete('file-abc123', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('delete file encodes the id as a single path segment', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['deleted' => true]),
    ]);

    Files::delete('../vector_stores/vs-123', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/files/..%2Fvector_stores%2Fvs-123');
});

test('delete file rejects dot segment ids', function (string $fileId): void {
    Http::fake();

    expect(fn () => Files::delete($fileId, provider: 'openai'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['', '.', '..']);

test('get file preserves raw response', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123', 'bytes' => 1024]),
    ]);

    $response = Files::get('file-abc123', provider: 'openai');

    expect($response->raw)->not->toBeNull()
        ->and($response->raw->json('bytes'))->toBe(1024);
});

test('put file preserves raw response', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-uploaded123', 'bytes' => 2048]),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'openai',
    );

    expect($response->raw)->not->toBeNull()
        ->and($response->raw->json('bytes'))->toBe(2048);
});

test('raw response is excluded when serialized', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    $response = Files::get('file-abc123', provider: 'openai');
    $serialized = serialize($response);
    $unserialized = unserialize($serialized);

    expect($unserialized->id)->toBe('file-abc123')
        ->and($unserialized->raw)->toBeNull();
});
