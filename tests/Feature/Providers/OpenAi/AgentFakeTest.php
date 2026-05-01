<?php

use Tests\Fixtures\Agents\OpenAiAgent;

test('openai agent can be faked', function () {
    OpenAiAgent::fake(['Test response']);

    $response = (new OpenAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('openai agent fake with closure', function () {
    OpenAiAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new OpenAiAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('openai agent fake with no predefined responses', function () {
    OpenAiAgent::fake();

    $response = (new OpenAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('openai agent fake records prompts', function () {
    OpenAiAgent::fake();

    (new OpenAiAgent)->prompt('Hello');

    OpenAiAgent::assertPrompted('Hello');
    OpenAiAgent::assertNotPrompted('Goodbye');
});

test('openai agent stream can be faked', function () {
    OpenAiAgent::fake(['Streamed response']);

    $response = (new OpenAiAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
