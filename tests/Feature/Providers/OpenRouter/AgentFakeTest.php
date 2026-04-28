<?php

use Tests\Fixtures\Agents\OpenRouterAgent;

test('openrouter agent can be faked', function () {
    OpenRouterAgent::fake(['Test response']);

    $response = (new OpenRouterAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('openrouter agent fake with closure', function () {
    OpenRouterAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new OpenRouterAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('openrouter agent fake with no predefined responses', function () {
    OpenRouterAgent::fake();

    $response = (new OpenRouterAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('openrouter agent fake records prompts', function () {
    OpenRouterAgent::fake();

    (new OpenRouterAgent)->prompt('Hello');

    OpenRouterAgent::assertPrompted('Hello');
    OpenRouterAgent::assertNotPrompted('Goodbye');
});

test('openrouter agent stream can be faked', function () {
    OpenRouterAgent::fake(['Streamed response']);

    $response = (new OpenRouterAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
