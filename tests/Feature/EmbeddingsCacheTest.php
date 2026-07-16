<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    config([
        'ai.providers.cohere' => [...config('ai.providers.cohere'), 'key' => 'test-key'],
        'ai.default_for_embeddings' => 'cohere',
        'ai.caching.embeddings.store' => 'array',
    ]);

    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);
});

test('cache is used when enabled explicitly', function (): void {
    Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('cache is used when enabled globally via config', function (): void {
    config(['ai.caching.embeddings.cache' => true]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('zero cache seconds bypasses an existing cached entry', function (): void {
    Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);

    Embeddings::for(['Hello'])->cache(0)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('negative cache seconds bypasses an existing cached entry', function (): void {
    Embeddings::for(['Hello'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);

    Embeddings::for(['Hello'])->cache(-1)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('toEmbeddings honors cache false even when enabled globally', function (): void {
    config(['ai.caching.embeddings.cache' => true]);

    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0', cache: false);
    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0', cache: false);

    expect(Http::recorded())->toHaveCount(2);
});

test('toEmbeddings uses cache when enabled globally', function (): void {
    config(['ai.caching.embeddings.cache' => true]);

    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');
    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});
