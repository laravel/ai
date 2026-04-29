<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

it('can generate embeddings with Infomaniak', function () {
    config(['ai.providers.infomaniak' => [
        'driver' => 'infomaniak',
        'key' => 'test-token',
        'url' => 'https://api.infomaniak.com/1/ai',
    ]]);

    Http::fake([
        '*/openai/embeddings' => Http::response([
            'data' => [
                ['embedding' => array_fill(0, 1536, 0.1)],
            ],
            'usage' => ['total_tokens' => 5],
        ]),
    ]);

    $response = Embeddings::for(['Hello world'])
        ->generate(provider: 'infomaniak', model: 'text-embedding-3-small');

    expect($response->first())->toHaveCount(1536);
});

it('uses correct base URL for embeddings', function () {
    config(['ai.providers.infomaniak' => [
        'driver' => 'infomaniak',
        'key' => 'test-token',
        'url' => 'https://custom.infomaniak.test/v1',
    ]]);

    Http::fake(function ($request) {
        expect($request->url())->toContain('https://custom.infomaniak.test/v1/openai/embeddings');

        return Http::response([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['total_tokens' => 3],
        ]);
    });

    Embeddings::for(['Test'])->generate(provider: 'infomaniak');
});
