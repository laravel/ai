<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

test('embeddings can be generated', function () {
    Event::fake();

    $response = Embeddings::for(['I love to watch Star Trek.'])->generate();

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class);
    expect($response->embeddings[0])->toHaveCount(1536);
    expect($response->meta->provider)->toEqual('openai');

    Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $event->prompt->timeout === 30);
});

test('embeddings can be generated with custom dimensions', function () {
    $response = Embeddings::for(['test text'])
        ->dimensions(256)
        ->generate();

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class);
    expect(count($response->embeddings[0]))->toEqual(256);
});
