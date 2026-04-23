<?php

use Tests\Fixtures\Agents\LiteLLMAgent;

test('litellm agent can be faked', function () {
    LiteLLMAgent::fake(['Test response']);

    $response = (new LiteLLMAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('litellm agent fake with closure', function () {
    LiteLLMAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new LiteLLMAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('litellm agent fake with no predefined responses', function () {
    LiteLLMAgent::fake();

    $response = (new LiteLLMAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('litellm agent fake records prompts', function () {
    LiteLLMAgent::fake();

    (new LiteLLMAgent)->prompt('Hello');

    LiteLLMAgent::assertPrompted('Hello');
    LiteLLMAgent::assertNotPrompted('Goodbye');
});

test('litellm agent stream can be faked', function () {
    LiteLLMAgent::fake(['Streamed response']);

    $response = (new LiteLLMAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
