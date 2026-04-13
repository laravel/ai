<?php

use Tests\Feature\Agents\OllamaAgent;

test('ollama agent can be faked', function () {
    OllamaAgent::fake(['Test response']);

    $response = (new OllamaAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('ollama agent fake with closure', function () {
    OllamaAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new OllamaAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('ollama agent fake with no predefined responses', function () {
    OllamaAgent::fake();

    $response = (new OllamaAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('ollama agent fake records prompts', function () {
    OllamaAgent::fake();

    (new OllamaAgent)->prompt('Hello');

    OllamaAgent::assertPrompted('Hello');
    OllamaAgent::assertNotPrompted('Goodbye');
});

test('ollama agent stream can be faked', function () {
    OllamaAgent::fake(['Streamed response']);

    $response = (new OllamaAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
