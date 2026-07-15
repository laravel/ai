<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Jobs\GenerateEmbeddings;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;

beforeEach(function (): void {
    config([
        'ai.providers.cohere' => [...config('ai.providers.cohere'), 'key' => 'test-key'],
        'ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key'],
        'ai.caching.embeddings.store' => 'array',
    ]);
});

test('cache key differs by provider options so distinct option sets do not collide', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_document'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    $observed = collect(Http::recorded())
        ->map(fn (array $pair): mixed => json_decode((string) $pair[0]->body(), true)['input_type'] ?? null)
        ->all();

    expect($observed)->toBe(['search_document', 'search_query']);
});

test('cache key is stable for the same provider options', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('cache key is insensitive to provider option key order', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query', 'truncate' => 'END'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['truncate' => 'END', 'input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(Http::recorded())->toHaveCount(1);
});

test('closure resolver receives the resolved provider and applies per-provider options', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
        'api.openai.com/*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ]),
    ]);

    $seen = [];

    Embeddings::for(['Hello'])
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return $provider->driver() === 'cohere'
                ? ['input_type' => 'search_query']
                : ['encoding_format' => 'base64'];
        })
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($seen)->toBe(['cohere']);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_query';
    });
});

test('closure provider options are not recorded on the queued prompt fake', function (): void {
    Embeddings::fake();

    Embeddings::for(['Hello'])
        ->withProviderOptions(fn (Provider $provider): array => ['input_type' => 'search_query'])
        ->queue(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::assertQueued(
        fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->providerOptions === [],
    );
});

test('closure provider options survive queue serialization round-trip', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    $pending = Embeddings::for(['Hello'])
        ->withProviderOptions(fn (Provider $provider): array => ['input_type' => 'search_query']);

    $job = new GenerateEmbeddings($pending, 'cohere', 'embed-v4.0');

    $restored = unserialize(serialize($job));

    expect($restored->pendingEmbeddings)->toBeInstanceOf(PendingEmbeddingsGeneration::class);

    $restored->handle();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_query';
    });
});

test('closure resolver returning null is treated as no options', function (): void {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(fn (): null => null)
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_document';
    });
});
