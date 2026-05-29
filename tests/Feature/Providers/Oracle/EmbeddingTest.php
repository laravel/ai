<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(fn () => $this->configureOracle());

test('embeddings target the embedText action and map the response vectors', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeEmbeddingsResponse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]),
    ]);

    $response = Embeddings::for(['first text', 'second text'])
        ->dimensions(1024)
        ->generate(provider: 'oracle', model: 'cohere.embed-multilingual-v3.0');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->first())->toEqual([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(4);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '20231130/actions/embedText')
            && $body['servingMode']['modelId'] === 'cohere.embed-multilingual-v3.0'
            && $body['inputs'] === ['first text', 'second text']
            && $body['inputType'] === 'SEARCH_DOCUMENT'
            && $body['truncate'] === 'END';
    });
});

test('v3 embedding models omit outputDimensions but v4 models forward it', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeEmbeddingsResponse(),
    ]);

    Embeddings::for(['text'])->dimensions(1024)->generate(provider: 'oracle', model: 'cohere.embed-multilingual-v3.0');

    Http::assertSent(fn ($request) => ! array_key_exists('outputDimensions', $request->data()));

    Embeddings::for(['text'])->dimensions(512)->generate(provider: 'oracle', model: 'cohere.embed-v4.0');

    Http::assertSent(fn ($request) => ($request->data()['outputDimensions'] ?? null) === 512);
});

test('embeddings forward provider options such as inputType', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeEmbeddingsResponse(),
    ]);

    Embeddings::for(['query text'])
        ->dimensions(1024)
        ->providerOptions(['inputType' => 'SEARCH_QUERY'])
        ->generate(provider: 'oracle', model: 'cohere.embed-multilingual-v3.0');

    Http::assertSent(fn ($request) => $request->data()['inputType'] === 'SEARCH_QUERY');
});
