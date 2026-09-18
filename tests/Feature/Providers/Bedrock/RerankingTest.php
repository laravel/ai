<?php

use Aws\MockHandler;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function (): void {
    config(['ai.providers.bedrock' => [
        ...config('ai.providers.bedrock'),
        'use_default_credential_provider' => false,
    ]]);
});

test('reranking request includes model, query, and documents', function (): void {
    $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    $command = $mock->getLastCommand();

    expect($command['modelId'])->toBe('cohere.rerank-v3-5:0')
        ->and(json_decode($command['body'], true))->toBe([
            'query' => 'What is Laravel?',
            'documents' => ['Laravel is a PHP framework', 'React is a JS library'],
            'api_version' => 2,
        ]);
});

test('reranking request omits api version for amazon rerank models', function (): void {
    $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'bedrock', model: 'amazon.rerank-v1:0');

    expect(json_decode($mock->getLastCommand()['body'], true))->toBe([
        'query' => 'What is Laravel?',
        'documents' => ['Laravel is a PHP framework', 'React is a JS library'],
    ]);
});

test('reranking request includes top_n when limit set', function (): void {
    $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    expect(json_decode($mock->getLastCommand()['body'], true)['top_n'])->toBe(2);
});

test('reranking response is correctly parsed into RankedDocuments', function (): void {
    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->fakeBedrockInvoke(fakeBedrockRerankingResponse())),
    );

    $response = Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('Laravel is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('bedrock')
        ->and($response->meta->model)->toBe('cohere.rerank-v3-5:0');
});

test('reranking uses default model when none specified', function (): void {
    $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock');

    expect($mock->getLastCommand()['modelId'])->toBe('cohere.rerank-v3-5:0');
});

test('reranking maps documents by index when results are returned out of order', function (): void {
    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->fakeBedrockInvoke([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.91],
                ['index' => 0, 'relevance_score' => 0.42],
                ['index' => 1, 'relevance_score' => 0.10],
            ],
        ])),
    );

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    $ranked = $response->collect();

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throttling maps to rate limited exception', function (): void {
    Ai::instance('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient(new MockHandler([
            $this->mockBedrockException('ThrottlingException', 429),
        ]))),
    );

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');
})->throws(RateLimitedException::class);

function fakeBedrockRerankingResponse(): array
{
    return [
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
    ];
}
