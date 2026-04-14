<?php

use Tests\Fixtures\Agents\GroqAgent;

test('groq agent can be faked', function () {
    GroqAgent::fake(['Test response']);

    $response = (new GroqAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('groq agent fake with closure', function () {
    GroqAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new GroqAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('groq agent fake with no predefined responses', function () {
    GroqAgent::fake();

    $response = (new GroqAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('groq agent fake records prompts', function () {
    GroqAgent::fake();

    (new GroqAgent)->prompt('Hello');

    GroqAgent::assertPrompted('Hello');
    GroqAgent::assertNotPrompted('Goodbye');
});

test('groq agent stream can be faked', function () {
    GroqAgent::fake(['Streamed response']);

    $response = (new GroqAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
