<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

test('embeddings can be generated', function (string $provider, string $apiKey, int $dimensions) {
    requiresApiKey($apiKey);

    Event::fake();

    $response = Embeddings::for(['I love to watch Star Trek.'])->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount($dimensions)
        ->and($response->meta->provider)->toEqual($provider);

    Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $event->prompt->timeout === 30);
})->with('embedding-providers');

test('embeddings can be generated with custom dimensions', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $response = Embeddings::for(['test text'])
        ->dimensions(256)
        ->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(256);
})->with('embedding-providers');
