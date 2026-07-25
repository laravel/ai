<?php

use Laravel\Ai\Exceptions\AiException;

describe('cohere embeddings', function () {
    test('unwraps Cohere Embed v4 vectors and reads the token count header', function () {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'response_type' => 'embeddings_floats',
        ], ['x-amzn-bedrock-input-token-count' => '7']);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        expect($response->first())->toBe([0.1, 0.2, 0.3]);
        expect($response->tokens)->toBe(7);
    });

    test('returns Cohere Embed v3 vectors from the bare list shape', function () {
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

    test('requests the given output dimension for Cohere Embed v4 models', function () {
        $mock = $this->bedrockInvokeMock(['embeddings' => ['float' => [[0.1, 0.2, 0.3]]]]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            256,
        );

        expect(json_decode($mock->getLastCommand()['body'], true))->toBe([
            'input_type' => 'search_document',
            'output_dimension' => 256,
            'texts' => ['The quick brown fox.'],
        ]);
    });

    test('omits the output dimension for Cohere Embed v3 models', function () {
        $mock = $this->bedrockInvokeMock(['embeddings' => [[0.1, 0.2, 0.3]]]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-english-v3',
            ['The quick brown fox.'],
            1024,
        );

        expect(json_decode($mock->getLastCommand()['body'], true))->not->toHaveKey('output_dimension');
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
