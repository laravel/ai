<?php

use Laravel\Ai\Exceptions\AiException;

describe('cohere embeddings', function () {
    test('unwraps the float vectors when embeddings are keyed by embedding type', function () {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]], 'int8' => [[1, 2, 3]]],
            'response_type' => 'embeddings_by_type',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        expect($response->first())->toBe([0.1, 0.2, 0.3]);
    });

    test('returns the vectors when embeddings are a bare list', function () {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
        ]);

        $response = $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-english-v3',
            ['The quick brown fox.', 'Jumps over the lazy dog.'],
            1024,
        );

        expect($response->first())->toBe([0.1, 0.2, 0.3]);
        expect($response->embeddings)->toHaveCount(2);
    });

    test('throws when the response carries no float embeddings', function () {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => ['int8' => [[1, 2, 3]]],
        ]);

        $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );
    })->throws(AiException::class, 'only float embeddings are supported');

    test('returns an empty response when the body cannot be decoded', function () {
        $client = $this->bedrockClient($this->bedrockInvokeMock(''));

        $response = $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        expect($response->embeddings)->toBe([]);
    });

    test('sends the Cohere request shape rather than the Titan one', function () {
        $mock = $this->bedrockInvokeMock(['embeddings' => ['float' => [[0.1, 0.2, 0.3]]]]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        expect(json_decode($mock->getLastCommand()['body'], true))->toBe([
            'input_type' => 'search_document',
            'texts' => ['The quick brown fox.'],
        ]);
    });

    test('routes region prefixed Cohere models to the Cohere request shape', function () {
        $mock = $this->bedrockInvokeMock(['embeddings' => ['float' => [[0.1, 0.2, 0.3]]]]);

        $response = $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'us.cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        expect(json_decode($mock->getLastCommand()['body'], true))->toHaveKey('texts');
        expect($response->first())->toBe([0.1, 0.2, 0.3]);
    });
});

describe('titan embeddings', function () {
    test('requests the given dimensions', function () {
        $mock = $this->bedrockInvokeMock(['embedding' => [0.1, 0.2, 0.3], 'inputTextTokenCount' => 5]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'amazon.titan-embed-text-v2:0',
            ['The quick brown fox.'],
            256,
        );

        expect(json_decode($mock->getLastCommand()['body'], true))->toBe([
            'inputText' => 'The quick brown fox.',
            'dimensions' => 256,
        ]);
    });
});
