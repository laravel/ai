<?php

use Tests\Fixtures\Agents\GeminiAgent;

test('gemini agent can be faked', function (): void {
    GeminiAgent::fake(['Test response']);

    $response = (new GeminiAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('gemini agent fake with closure', function (): void {
    GeminiAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new GeminiAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('gemini agent fake with no predefined responses', function (): void {
    GeminiAgent::fake();

    $response = (new GeminiAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('gemini agent fake records prompts', function (): void {
    GeminiAgent::fake();

    (new GeminiAgent)->prompt('Hello');

    GeminiAgent::assertPrompted('Hello');
    GeminiAgent::assertNotPrompted('Goodbye');
});

test('gemini agent stream can be faked', function (): void {
    GeminiAgent::fake(['Streamed response']);

    $response = (new GeminiAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
