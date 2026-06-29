<?php

use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;

function embeddingsGateway(): object
{
    return new class extends BedrockTextGateway
    {
        public function callParseCohereEmbeddings(array $result): array
        {
            return $this->parseCohereEmbeddings($result);
        }
    };
}

test('parses Cohere Embed v3 bare list of vectors', function () {
    $embeddings = embeddingsGateway()->callParseCohereEmbeddings([
        'embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
    ]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('parses Cohere Embed v4 type-keyed embeddings object', function () {
    $embeddings = embeddingsGateway()->callParseCohereEmbeddings([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
    ]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('falls back to the first requested type when float is absent', function () {
    $embeddings = embeddingsGateway()->callParseCohereEmbeddings([
        'embeddings' => ['int8' => [[1, 2, 3], [4, 5, 6]]],
    ]);

    expect($embeddings)->toBe([[1, 2, 3], [4, 5, 6]]);
});

test('prefers the float type when multiple types are present', function () {
    $embeddings = embeddingsGateway()->callParseCohereEmbeddings([
        'embeddings' => [
            'int8' => [[1, 2, 3]],
            'float' => [[0.1, 0.2, 0.3]],
        ],
    ]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3]]);
});

test('returns an empty list when embeddings are absent', function () {
    expect(embeddingsGateway()->callParseCohereEmbeddings([]))->toBe([]);
});
