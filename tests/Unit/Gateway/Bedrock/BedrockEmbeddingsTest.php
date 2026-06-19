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

test('returns an empty list when embeddings are absent', function () {
    expect(embeddingsGateway()->callParseCohereEmbeddings([]))->toBe([]);
});
