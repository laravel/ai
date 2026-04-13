<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

beforeEach(fn () => requiresApiKey('OPENAI_API_KEY'));

test('embeddings can be generated', function () {
    Event::fake();

    $response = Embeddings::for(['I love to watch Star Trek.'])->generate();

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(1536)
        ->and($response->meta->provider)->toEqual('openai');

    Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $event->prompt->timeout === 30);
});

test('embeddings can be generated with custom dimensions', function () {
    $response = Embeddings::for(['test text'])
        ->dimensions(256)
        ->generate();

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(256);
});
