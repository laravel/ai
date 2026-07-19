<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
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

test('custom headers are included in embeddings request', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('X-Tenant', $body);
    });
});

test('embeddings request does not include custom headers when none are specified', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Tenant'));
});

test('closure resolver receives the resolved provider and applies per-provider headers', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    $seen = [];

    Embeddings::for(['Hello'])
        ->withHeaders(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return $provider->driver() === 'openai'
                ? ['X-Tenant' => 'acme']
                : [];
        })
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect($seen)->toBe(['openai']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme'));
});

test('queued embeddings prompt carries array headers', function (): void {
    Embeddings::fake();

    Embeddings::for(['Hello'])
        ->withHeaders(['X-Tenant' => 'acme'])
        ->queue(provider: 'openai', model: 'text-embedding-3-small');

    Embeddings::assertQueued(
        fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->headers === ['X-Tenant' => 'acme'],
    );
});

test('cache key differs by headers so distinct header sets do not collide', function (): void {
    Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withHeaders(['X-Tenant' => 'globex'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect(Http::recorded())->toHaveCount(2);
});

test('custom headers are included in transcription request', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Hello'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme'));
});

test('custom headers are included in image request', function (): void {
    Http::fake(['api.openai.com/*' => Http::response([
        'data' => [['b64_json' => base64_encode('fake-image')]],
    ])]);

    Image::of('A red apple')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'dall-e-3');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('X-Tenant', $body);
    });
});

test('custom headers are included in audio request', function (): void {
    Http::fake(['api.openai.com/*' => Http::response('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('X-Tenant', $body);
    });
});

test('custom headers are included in reranking request', function (): void {
    Http::fake(['api.cohere.com/*' => Http::response([
        'results' => [['index' => 0, 'relevance_score' => 0.95]],
    ])]);

    Reranking::of(['Laravel is a PHP framework'])
        ->withHeaders(['X-Tenant' => 'acme'])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && ! array_key_exists('X-Tenant', $body);
    });
});
