<?php

use Laravel\Ai\Enums\PromptCacheTarget;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

test('system target appends a cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent([PromptCacheTarget::System]))
    );

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default']],
    ])->and($parameters)->not->toHaveKey('prompt_cache');
});

test('tools target appends a cache point to the tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent(['tools'])),
        [new FixedNumberGenerator],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and(end($parameters['toolConfig']['tools']))->toBe(['cachePoint' => ['type' => 'default']]);
});

test('an empty prompt cache option leaks nothing to aws', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent),
        [new FixedNumberGenerator],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and($parameters)->not->toHaveKey('prompt_cache')
        ->and($parameters['toolConfig']['tools'])->each->toHaveKey('toolSpec');
});

test('an unknown prompt cache target throws', function (): void {
    $this->capturedConverseParameters(TextGenerationOptions::forAgent(new PromptCacheAgent(['messages'])));
})->throws(ValueError::class);

test('a falsy prompt cache option is a no-op rather than an error', function (mixed $cache): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent($cache)),
        [new FixedNumberGenerator],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and($parameters['toolConfig']['tools'])->each->toHaveKey('toolSpec');
})->with([false, 0, '', null]);

test('cache points survive provider options that override the same keys', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent([PromptCacheTarget::System], options: [
            'system' => [['text' => 'Overridden instructions.']],
        ])),
    );

    expect($parameters['system'])->toBe([
        ['text' => 'Overridden instructions.'],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('the tools target is a no-op when the request carries no tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent([PromptCacheTarget::Tools], withTools: false)),
    );

    expect($parameters)->not->toHaveKey('toolConfig');
});
