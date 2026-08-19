<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Image;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Reranking;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config([
        'ai.providers.cohere' => [...config('ai.providers.cohere'), 'key' => 'test-key'],
        'ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key'],
        'ai.caching.embeddings.store' => 'array',
    ]);
});

function fakeEmbeddingsHeadersResponse()
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ]);
}

test('extra headers are sent with embeddings requests and never in the body', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('extra_headers', $body)
            && ! array_key_exists('X-Tenant', $body);
    });
});

test('extra headers are sent with transcription requests', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Hello'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme')
        && multipartField($request, 'extra_headers') === null);
});

test('extra headers resolved from a closure survive serialization', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    $pending = Embeddings::for(['Hello'])->withProviderOptions(
        fn (Provider $provider): array => ['extra_headers' => ['X-Tenant' => $provider->driver().'-acme']],
    );

    unserialize(serialize($pending))->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'openai-acme'));
});

test('extra headers do not participate in the embeddings cache key', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'globex']])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect(Http::recorded())->toHaveCount(1);
});

test('extra headers replace configured headers regardless of casing', function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'headers' => ['X-Tenant' => 'from-config'],
    ]]);

    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['extra_headers' => ['x-tenant' => 'from-request']])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request): bool => $request->header('X-Tenant') === ['from-request']);
});

test('extra headers are sent with image requests', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

    Image::of('A red apple')
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->generate(provider: 'openai', model: 'dall-e-3');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('extra_headers', $body);
    });
});

test('extra headers are sent with audio requests', function (): void {
    Http::fake(['api.openai.com/*' => Http::response('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('extra_headers', $body);
    });
});

test('extra headers are sent with reranking requests', function (): void {
    Http::fake(['api.cohere.com/*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.95]]])]);

    Reranking::of(['Laravel is a PHP framework'])
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('extra_headers', $body);
    });
});

test('extra headers are sent with file uploads', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-abc123'])]);

    Document::fromString('Hello, World!', 'text/plain')
        ->as('hello.txt')
        ->withProviderOptions(['extra_headers' => ['X-Tenant' => 'acme']])
        ->put(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme')
        && multipartField($request, 'extra_headers') === null);
});
