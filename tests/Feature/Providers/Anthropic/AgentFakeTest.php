<?php

use Tests\Fixtures\Agents\AnthropicAgent;

test('anthropic agent can be faked', function (): void {
    AnthropicAgent::fake(['Test response']);

    $response = (new AnthropicAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('anthropic agent fake with closure', function (): void {
    AnthropicAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new AnthropicAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('anthropic agent fake with no predefined responses', function (): void {
    AnthropicAgent::fake();

    $response = (new AnthropicAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('anthropic agent fake records prompts', function (): void {
    AnthropicAgent::fake();

    (new AnthropicAgent)->prompt('Hello');

    AnthropicAgent::assertPrompted('Hello');
    AnthropicAgent::assertNotPrompted('Goodbye');
});

test('anthropic agent stream can be faked', function (): void {
    AnthropicAgent::fake(['Streamed response']);

    $response = (new AnthropicAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
