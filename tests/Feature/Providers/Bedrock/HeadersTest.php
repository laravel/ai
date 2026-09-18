<?php

use Aws\MockHandler;
use Aws\Result;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;

function generateBedrockStep(Provider $provider): MockHandler
{
    $mock = new MockHandler([new Result([
        'output' => ['message' => ['content' => [['text' => 'Hello']]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ])]);

    test()->gatewayWithHandler($mock)->generateTextStep(
        $provider,
        'anthropic.claude-opus-4-7-v1:0',
        null,
        [new UserMessage('Hi')],
        [],
        null,
        new TextGenerationOptions,
        null,
        new StepContext,
    );

    return $mock;
}

test('custom headers are included in bedrock requests', function (): void {
    $mock = generateBedrockStep($this->bedrockProvider()->withHeaders(['X-Custom-Header' => 'bedrock-value']));

    expect($mock->getLastRequest()->getHeaderLine('X-Custom-Header'))->toBe('bedrock-value');
});

test('bedrock requests do not include custom headers when none are set', function (): void {
    $mock = generateBedrockStep($this->bedrockProvider());

    expect($mock->getLastRequest()->hasHeader('X-Custom-Header'))->toBeFalse();
});
