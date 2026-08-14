<?php

use Aws\History;
use Aws\Middleware;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\HeadersAgent;

test('custom headers are forwarded via @http on converse', function (): void {
    $history = new History;

    $client = $this->fakeBedrockConverse([
        'output' => ['message' => ['content' => [['text' => 'Hello']]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ]);

    $client->getHandlerList()->appendSign(Middleware::history($history));

    $gateway = $this->gatewayWithClient($client);

    $gateway->generateTextStep(
        $this->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        null,
        [new UserMessage('Hi')],
        [],
        null,
        TextGenerationOptions::forAgent(new HeadersAgent),
        null,
        new StepContext,
    );

    expect($history)->not->toBeEmpty()
        ->and($history->getLastCommand()['@http']['headers'])->toEqual([
            'X-Custom-Header' => 'bedrock-value',
        ]);
});

test('converse request does not include custom headers when agent does not implement interface', function (): void {
    $history = new History;

    $client = $this->fakeBedrockConverse([
        'output' => ['message' => ['content' => [['text' => 'Hello']]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ]);

    $client->getHandlerList()->appendSign(Middleware::history($history));

    $gateway = $this->gatewayWithClient($client);

    $gateway->generateTextStep(
        $this->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        null,
        [new UserMessage('Hi')],
        [],
        null,
        new TextGenerationOptions,
        null,
        new StepContext,
    );

    expect($history)->not->toBeEmpty()
        ->and(data_get($history->getLastCommand()->toArray(), '@http.headers'))->toBeNull();
});
