<?php

use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Messages\AssistantMessage;

describe('reasoning capture', function () {
    test('captures reasoning content into providerContentBlocks', function () {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [
                        ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
                        ['text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            'stopReason' => 'end_turn',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            'invocation-1',
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        $assistant = $response->messages->first(fn ($m) => $m instanceof AssistantMessage);

        expect($assistant->providerContentBlocks)->toEqual([
            ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
            ['text' => 'Hello'],
        ]);
        expect($assistant->content)->toBe('Hello');
    });
});
