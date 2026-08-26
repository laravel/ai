<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\EmbeddingsCountMismatchException;

function fakeCohereEmbedding(string $text): array
{
    return [crc32($text), 0.5];
}

beforeEach(function (): void {
    config([
        'ai.providers.cohere' => [...config('ai.providers.cohere'), 'key' => 'test-key'],
        'ai.default_for_embeddings' => 'cohere',
        'ai.caching.embeddings.store' => 'array',
    ]);

    Http::fake([
        'api.cohere.com/*' => fn (Request $request) => Http::response([
            'embeddings' => ['float' => array_map(fakeCohereEmbedding(...), $request->data()['texts'])],
            'meta' => ['billed_units' => ['input_tokens' => count($request->data()['texts'])]],
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

test('inputs that differ only in their boundaries do not share a cache entry', function (): void {
    Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['a', 'b-c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('identical string inputs still share a cache entry', function (): void {
    Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['a-b', 'c'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('reordered string inputs do not share a cache entry', function (): void {
    Embeddings::for(['a', 'b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['b', 'a'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('inputs can opt out of individual caching', function (): void {
    Embeddings::for(['a', 'b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['b'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('each input is cached individually when opted in', function (): void {
    Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    $response = Embeddings::for(['b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1)
        ->and($response->embeddings)->toEqual([fakeCohereEmbedding('b')]);
});

test('individual caching can be enabled globally via config', function (): void {
    config([
        'ai.caching.embeddings.cache' => true,
        'ai.caching.embeddings.individually' => true,
    ]);

    Embeddings::for(['a', 'b'])->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['b'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('individual caching is enabled when the config value is missing', function (): void {
    config(['ai.caching.embeddings' => [
        'cache' => false,
        'store' => 'array',
    ]]);

    Embeddings::for(['a', 'b'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['b'])->cache(3600)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('fully individually cached requests are served in any input order without a provider call', function (): void {
    Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    $response = Embeddings::for(['b', 'a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1)
        ->and($response->tokens)->toBe(0)
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('embed-v4.0')
        ->and($response->embeddings)->toEqual([fakeCohereEmbedding('b'), fakeCohereEmbedding('a')]);
});

test('partially cached requests only generate embeddings for the uncached inputs', function (): void {
    Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    $response = Embeddings::for(['c', 'a', 'd'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);

    [$request] = Http::recorded()[1];

    expect($request->data()['texts'])->toBe(['c', 'd'])
        ->and($response->tokens)->toBe(2)
        ->and($response->embeddings)->toEqual([
            fakeCohereEmbedding('c'),
            fakeCohereEmbedding('a'),
            fakeCohereEmbedding('d'),
        ]);
});

test('embeddings generated by a partially cached request are cached individually', function (): void {
    Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('duplicate inputs resolve to the same individual cache entry', function (): void {
    $response = Embeddings::for(['a', 'a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($response->embeddings)->toEqual([fakeCohereEmbedding('a'), fakeCohereEmbedding('a')]);

    Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('individual caching throws when the provider returns a mismatched embedding count', function (): void {
    Embeddings::fake([
        [[0.1, 0.2, 0.3]],
    ]);

    Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(EmbeddingsCountMismatchException::class, 'Provider returned 1 embeddings for 2 inputs.');

test('individually cached inputs do not share entries with batch cached inputs', function (): void {
    Embeddings::for(['a'])->cache(3600, individually: false)->generate(provider: 'cohere', model: 'embed-v4.0');
    Embeddings::for(['a'])->cache(3600, individually: true)->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(2);
});

test('toEmbeddings uses cache when enabled globally', function (): void {
    config(['ai.caching.embeddings.cache' => true]);

    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');
    str('hello world')->toEmbeddings(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});
