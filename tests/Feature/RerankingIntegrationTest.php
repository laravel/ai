<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Reranking as RerankingFacade;
use Laravel\Ai\Responses\RerankingResponse;

uses()->group('integration');

test('documents can be reranked', function () {
    Event::fake();

    $response = RerankingFacade::of([
        'Python is a high-level, general-purpose programming language.',
        'Laravel is a PHP web application framework with expressive, elegant syntax.',
        'React is a JavaScript library for building user interfaces.',
    ])->rerank('What is Laravel?');

    expect($response)->toBeInstanceOf(RerankingResponse::class)
        ->toHaveCount(3)
        ->and($response->meta->provider)->toEqual('cohere')
        ->and($response->first()->document)->toContain('Laravel');

    Event::assertDispatched(Reranking::class);
    Event::assertDispatched(Reranked::class);
});

test('documents can be reranked with limit', function () {
    $response = RerankingFacade::of([
        'Django is a Python web framework.',
        'Rails is a Ruby web framework.',
        'Laravel is a PHP web application framework.',
        'Express is a Node.js web framework.',
        'Spring is a Java web framework.',
    ])->limit(2)->rerank('PHP frameworks');

    expect($response)->toHaveCount(2)
        ->and($response->first()->score)->toBeGreaterThan($response->results[1]->score)
        ->and($response->first()->document)->toContain('Laravel');
});

test('collections can be reranked using string field', function () {
    $items = new Collection([
        ['id' => 1, 'content' => 'Django is a Python web framework.'],
        ['id' => 2, 'content' => 'Laravel is a PHP web application framework.'],
        ['id' => 3, 'content' => 'React is a JavaScript library.'],
    ]);

    $reranked = $items->rerank(by: 'content', query: 'PHP frameworks', limit: 2);

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
});

test('collections can be reranked using array fields', function () {
    $items = new Collection([
        ['id' => 1, 'title' => 'Django Guide', 'body' => 'Learn Python web development.'],
        ['id' => 2, 'title' => 'Laravel Guide', 'body' => 'Learn PHP web development.'],
        ['id' => 3, 'title' => 'React Guide', 'body' => 'Learn JavaScript UI development.'],
    ]);

    $reranked = $items->rerank(by: ['title', 'body'], query: 'PHP frameworks', limit: 2);

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
});

test('collections can be reranked using closure', function () {
    $items = new Collection([
        ['id' => 1, 'title' => 'Django', 'body' => 'Python web framework.'],
        ['id' => 2, 'title' => 'Laravel', 'body' => 'PHP web framework.'],
        ['id' => 3, 'title' => 'React', 'body' => 'JavaScript library.'],
    ]);

    $reranked = $items->rerank(
        fn ($item) => $item['title'].': '.$item['body'],
        'PHP frameworks',
        limit: 2
    );

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
});
