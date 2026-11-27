<?php

use Laravel\Ai\Attributes\CacheConversation;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

test('cache instructions attribute appends a cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions] class extends PromptCacheAgent {})
    );

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('cache tool definitions attribute appends a cache point to the tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class extends PromptCacheAgent {}),
        [new FixedNumberGenerator],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and(end($parameters['toolConfig']['tools']))->toBe(['cachePoint' => ['type' => 'default']]);
});

test('an agent without cache attributes adds no cache points', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent),
        [new FixedNumberGenerator],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and($parameters['toolConfig']['tools'])->each->toHaveKey('toolSpec');
});

test('a requested ttl is added to the bedrock cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] class extends PromptCacheAgent {})
    );

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default', 'ttl' => '1h']],
    ]);
});

test('a longer instructions ttl requires the tools cache to use the same ttl', function (): void {
    $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {}),
        [new FixedNumberGenerator],
    );
})->throws(InvalidArgumentException::class);

test('cache points survive provider options that override the same keys', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions] class(options: ['system' => [['text' => 'Overridden instructions.']]]) extends PromptCacheAgent {}),
    );

    expect($parameters['system'])->toBe([
        ['text' => 'Overridden instructions.'],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('the tools target is a no-op when the request carries no tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class(withTools: false) extends PromptCacheAgent {}),
    );

    expect($parameters)->not->toHaveKey('toolConfig');
});

test('cache conversation attribute appends a cache point after the last message', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheConversation] class extends PromptCacheAgent {}),
        messages: [new UserMessage('Hi'), new AssistantMessage('Hello'), new UserMessage('How are you?')],
    );

    expect($parameters['messages'][0]['content'])->toBe([['text' => 'Hi']])
        ->and(end($parameters['messages'])['content'])->toBe([
            ['text' => 'How are you?'],
            ['cachePoint' => ['type' => 'default']],
        ]);
});

test('the conversation cache point uses its own ttl', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] #[CacheConversation('5m')] class extends PromptCacheAgent {}),
        messages: [new UserMessage('Hi')],
    );

    expect(end($parameters['messages'])['content'])->toBe([
        ['text' => 'Hi'],
        ['cachePoint' => ['type' => 'default', 'ttl' => '5m']],
    ]);
});

test('a one-hour conversation cache requires earlier caches to use the same ttl', function (): void {
    $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('5m')] #[CacheConversation('1h')] class extends PromptCacheAgent {}),
        messages: [new UserMessage('Hi')],
    );
})->throws(InvalidArgumentException::class);

test('an agent without the conversation attribute adds no message cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions] class extends PromptCacheAgent {}),
        messages: [new UserMessage('Hi')],
    );

    expect(end($parameters['messages'])['content'])->toBe([['text' => 'Hi']]);
});
