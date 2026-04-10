<?php

use Tests\Feature\Agents\AnthropicAgent;

test('anthropic agent can be faked', function () {
    AnthropicAgent::fake(['Test response']);

    $response = (new AnthropicAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('anthropic agent fake with closure', function () {
    AnthropicAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new AnthropicAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('anthropic agent fake with no predefined responses', function () {
    AnthropicAgent::fake();

    $response = (new AnthropicAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('anthropic agent fake records prompts', function () {
    AnthropicAgent::fake();

    (new AnthropicAgent)->prompt('Hello');

    AnthropicAgent::assertPrompted('Hello');
    AnthropicAgent::assertNotPrompted('Goodbye');
});

test('anthropic agent stream can be faked', function () {
    AnthropicAgent::fake(['Streamed response']);

    $response = (new AnthropicAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
