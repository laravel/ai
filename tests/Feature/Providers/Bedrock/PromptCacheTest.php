<?php

use Aws\MockHandler;
use Aws\Result;
use Laravel\Ai\Enums\PromptCacheTarget;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function bedrockConverseParameters(PromptCacheAgent $agent): array
{
    $mock = new MockHandler([new Result([
        'output' => ['message' => ['content' => [['text' => 'Hi']]]],
        'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        'stopReason' => 'end_turn',
    ])]);

    test()->gatewayWithClient(test()->bedrockClient($mock))->generateTextStep(
        test()->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        'You are a helpful assistant.',
        [new UserMessage('Hi')],
        $agent->withTools ? [new FixedNumberGenerator] : [],
        null,
        new TextGenerationOptions(agent: $agent),
        null,
        new StepContext,
    );

    return $mock->getLastCommand()->toArray();
}

test('system target appends a cache point after the instructions', function (): void {
    $parameters = bedrockConverseParameters(new PromptCacheAgent(cache: [PromptCacheTarget::System], withTools: false));

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default']],
    ])->and($parameters)->not->toHaveKey('prompt_cache');
});

test('tools target appends a cache point after the tool list', function (): void {
    $parameters = bedrockConverseParameters(new PromptCacheAgent(cache: ['tools']));

    $tools = $parameters['toolConfig']['tools'];

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and(end($tools))->toBe(['cachePoint' => ['type' => 'default']])
        ->and($tools)->toHaveCount(2);
});

test('empty prompt cache leaks nothing to aws', function (): void {
    $parameters = bedrockConverseParameters(new PromptCacheAgent(cache: []));

    expect($parameters)->not->toHaveKey('prompt_cache')
        ->and($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and($parameters['toolConfig']['tools'])->toHaveCount(1);
});

test('unknown prompt cache target throws', function (): void {
    bedrockConverseParameters(new PromptCacheAgent(cache: ['toolz']));
})->throws(ValueError::class);
