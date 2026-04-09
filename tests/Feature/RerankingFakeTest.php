<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

test('can fake reranking', function () {
    Reranking::fake();

    $response = Reranking::of([
        'Laravel is a PHP framework',
        'Python is a programming language',
        'React is a JavaScript library',
    ])->rerank('What is Laravel?');

    expect($response)->toHaveCount(3);
    expect($response->first())->toBeInstanceOf(RankedDocument::class);
});

test('can fake reranking with limit', function () {
    Reranking::fake();

    $response = Reranking::of([
        'Laravel is a PHP framework',
        'Python is a programming language',
        'React is a JavaScript library',
        'Vue is a JavaScript framework',
        'Ruby is a programming language',
    ])->limit(3)->rerank('What is Laravel?');

    expect($response)->toHaveCount(3);
});

test('can fake reranking with custom response', function () {
    Reranking::fake([
        [
            new RankedDocument(index: 0, document: 'First doc', score: 0.95),
            new RankedDocument(index: 1, document: 'Second doc', score: 0.75),
        ],
    ]);

    $response = Reranking::of(['First doc', 'Second doc'])->rerank('query');

    expect($response)->toHaveCount(2);
    expect($response->first()->score)->toEqual(0.95);
    expect($response->first()->document)->toEqual('First doc');
});

test('can fake reranking with closure', function () {
    Reranking::fake(function (RerankingPrompt $prompt) {
        return (new Collection($prompt->documents))->map(fn ($doc, $index) => new RankedDocument(
            index: $index,
            document: $doc,
            score: 1.0 - ($index * 0.1),
        ))->all();
    });

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])->rerank('test query');

    expect($response)->toHaveCount(3);
    expect($response->first()->score)->toEqual(1.0);
    expect($response->first()->document)->toEqual('Doc A');
});

test('can assert reranked', function () {
    Reranking::fake();

    Reranking::of(['Laravel is great', 'PHP is cool'])->rerank('Laravel');

    Reranking::assertReranked(function (RerankingPrompt $prompt) {
        return $prompt->contains('Laravel');
    });

    Reranking::assertReranked(function (RerankingPrompt $prompt) {
        return $prompt->documentsContain('Laravel is great');
    });
});

test('can assert not reranked', function () {
    Reranking::fake();

    Reranking::of(['Laravel is great'])->rerank('Laravel');

    Reranking::assertNotReranked(function (RerankingPrompt $prompt) {
        return $prompt->contains('Python');
    });

    Reranking::assertNotReranked(function (RerankingPrompt $prompt) {
        return $prompt->documentsContain('Python is great');
    });
});

test('can assert nothing reranked', function () {
    Reranking::fake();

    Reranking::assertNothingReranked();
});

test('can prevent stray rerankings', function () {
    Reranking::fake()->preventStrayRerankings();

    Reranking::of(['Doc 1', 'Doc 2'])->rerank('query');
})->throws(RuntimeException::class);

test('fake reranking shuffles documents', function () {
    Reranking::fake();

    $documents = ['Doc A', 'Doc B', 'Doc C', 'Doc D', 'Doc E'];

    $response = Reranking::of($documents)->rerank('query');

    expect($response)->toHaveCount(5);

    foreach ($response as $result) {
        expect($documents)->toContain($result->document);
        expect($result->document)->toEqual($documents[$result->index]);
    }
});

test('can iterate over response', function () {
    Reranking::fake();

    $response = Reranking::of(['Doc A', 'Doc B'])->rerank('query');

    $documents = [];

    foreach ($response as $result) {
        $documents[] = $result->document;
    }

    expect($documents)->toHaveCount(2);
});

test('can get documents in reranked order', function () {
    Reranking::fake([
        [
            new RankedDocument(index: 1, document: 'Second', score: 0.9),
            new RankedDocument(index: 0, document: 'First', score: 0.5),
        ],
    ]);

    $response = Reranking::of(['First', 'Second'])->rerank('query');

    expect($response->documents()->all())->toEqual(['Second', 'First']);
});

test('rerank accepts ai provider enum', function () {
    Reranking::fake();

    Reranking::of(['Laravel is great', 'PHP is cool'])->rerank('Laravel', provider: Lab::Cohere);

    Reranking::assertReranked(function (RerankingPrompt $prompt) {
        return $prompt->contains('Laravel');
    });
});

test('prompt records limit', function () {
    Reranking::fake();

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])->limit(2)->rerank('query');

    Reranking::assertReranked(function (RerankingPrompt $prompt) {
        return $prompt->limit === 2 && $prompt->count() === 3;
    });
});
