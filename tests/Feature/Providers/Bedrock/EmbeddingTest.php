<?php

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
});
