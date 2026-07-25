<?php

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Cohere\Concerns\ParsesEmbeddings;

function cohereEmbeddingsParser(): object
{
    return new class
    {
        use ParsesEmbeddings;

        public function parse(mixed $embeddings): array
        {
            return $this->parseCohereEmbeddings($embeddings);
        }
    };
}

test('parses the bare list of vectors returned by Embed v3', function () {
    $embeddings = cohereEmbeddingsParser()->parse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('unwraps the float type keyed by Embed v4', function () {
    $embeddings = cohereEmbeddingsParser()->parse(['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('throws when the response carries no float embeddings', function () {
    cohereEmbeddingsParser()->parse(['int8' => [[1, 2, 3]], 'binary' => [[1, 0, 1]]]);
})->throws(AiException::class, 'Cohere returned [int8, binary] embeddings, but only float embeddings are supported.');

test('returns an empty list when the embeddings are absent', function () {
    expect(cohereEmbeddingsParser()->parse([]))->toBe([]);
});

test('returns an empty list when the response body could not be decoded', function () {
    expect(cohereEmbeddingsParser()->parse(null))->toBe([]);
});
