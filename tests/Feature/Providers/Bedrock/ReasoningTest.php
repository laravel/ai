<?php

use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Messages\AssistantMessage;

describe('reasoning capture', function (): void {
    test('joins reasoning blocks into the response reasoning', function (): void {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [
                        ['reasoningContent' => ['reasoningText' => ['text' => 'First.', 'signature' => 'sig-1']]],
                        ['reasoningContent' => ['redactedContent' => 'encrypted-blob']],
                        ['reasoningContent' => ['reasoningText' => ['text' => 'Second.', 'signature' => 'sig-2']]],
                        ['text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            'stopReason' => 'end_turn',
        ]);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        expect($response->reasoning)->toBe("First.\n\nSecond.");
    });

    test('captures reasoning content into providerContentBlocks', function (): void {
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
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        $assistant = $response->messages->first(fn ($m): bool => $m instanceof AssistantMessage);

        expect($assistant->providerContentBlocks)->toEqual([
            ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
            ['text' => 'Hello'],
        ]);
        expect($assistant->content)->toBe('Hello');
    });
});
