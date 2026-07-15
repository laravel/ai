<?php

use Tests\Fixtures\Agents\OllamaAgent;

test('ollama agent can be faked', function (): void {
    OllamaAgent::fake(['Test response']);

    $response = (new OllamaAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('ollama agent fake with closure', function (): void {
    OllamaAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new OllamaAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('ollama agent fake with no predefined responses', function (): void {
    OllamaAgent::fake();

    $response = (new OllamaAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('ollama agent fake records prompts', function (): void {
    OllamaAgent::fake();

    (new OllamaAgent)->prompt('Hello');

    OllamaAgent::assertPrompted('Hello');
    OllamaAgent::assertNotPrompted('Goodbye');
});

test('ollama agent stream can be faked', function (): void {
    OllamaAgent::fake(['Streamed response']);

    $response = (new OllamaAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
