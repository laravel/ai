<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files;
use Laravel\Ai\Stores;

beforeEach(function (): void {
    foreach (['openai', 'anthropic', 'openrouter', 'gemini'] as $provider) {
        config(["ai.providers.{$provider}.key" => 'test-key']);
    }

    config(['ai.providers.azure.key' => 'test-key', 'ai.providers.azure.url' => 'https://test-resource.openai.azure.com']);

    Http::fake();
});

dataset('unsafe ids', [
    'parent traversal' => ['../../vector_stores/victim', '..%2F..%2Fvector_stores%2Fvictim'],
    'nested path' => ['abc/def', 'abc%2Fdef'],
    'query injection' => ['abc?force=true', 'abc%3Fforce%3Dtrue'],
    'fragment' => ['abc#x', 'abc%23x'],
    'encoded slash' => ['abc%2F..', 'abc%252F..'],
]);

test('file ids are sent as a single path segment', function (string $provider, string $url, string $id, string $encoded): void {
    Files::delete($id, provider: $provider);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
})->with([
    'openai' => ['openai', 'https://api.openai.com/v1/files/%s'],
    'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/files/%s'],
    'anthropic' => ['anthropic', 'https://api.anthropic.com/v1/files/%s'],
    'openrouter' => ['openrouter', 'https://openrouter.ai/api/v1/files/%s'],
    'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/files/%s'],
])->with('unsafe ids');

test('store ids are sent as a single path segment', function (string $provider, string $url, string $id, string $encoded): void {
    Stores::delete($id, provider: $provider);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
})->with([
    'openai' => ['openai', 'https://api.openai.com/v1/vector_stores/%s'],
    'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/vector_stores/%s'],
    'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/%s?force=true'],
])->with('unsafe ids');

test('store document ids are sent as a single path segment', function (string $provider, string $url, string $id, string $encoded): void {
    $instance = app(AiManager::class)->instance($provider);

    $instance->storeGateway()->removeFile($instance, 'store123', $id);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
})->with([
    'openai' => ['openai', 'https://api.openai.com/v1/vector_stores/store123/files/%s'],
    'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/vector_stores/store123/files/%s'],
    'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/store123/documents/%s?force=true'],
])->with('unsafe ids');

test('gemini resource ids cannot name a different resource through their prefix', function (string $operation, string $id, string $url): void {
    $gemini = app(AiManager::class)->instance('gemini');

    match ($operation) {
        'file' => Files::delete($id, provider: 'gemini'),
        'store' => Stores::delete($id, provider: 'gemini'),
        'document' => $gemini->storeGateway()->removeFile($gemini, 'store123', $id),
    };

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === "https://generativelanguage.googleapis.com/v1beta/{$url}");
})->with([
    'file pointing at a store' => ['file', 'files/../fileSearchStores/victim', 'files/..%2FfileSearchStores%2Fvictim'],
    'store pointing at a document' => ['store', 'fileSearchStores/victim/documents/doc456', 'fileSearchStores/victim%2Fdocuments%2Fdoc456?force=true'],
    'document pointing at a store' => ['document', 'fileSearchStores/victim', 'fileSearchStores/store123/documents/fileSearchStores%2Fvictim?force=true'],
    'document in a different store' => ['document', 'fileSearchStores/other/documents/doc456', 'fileSearchStores/store123/documents/fileSearchStores%2Fother%2Fdocuments%2Fdoc456?force=true'],
]);

test('dot segment ids are rejected before a request is sent', function (string $id): void {
    expect(fn () => Files::delete($id, provider: 'openai'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['', '.', '..']);
