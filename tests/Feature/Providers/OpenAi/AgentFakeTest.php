<?php

use Tests\Fixtures\Agents\OpenAiAgent;

test('openai agent can be faked', function (): void {
    OpenAiAgent::fake(['Test response']);

    $response = (new OpenAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('openai agent fake with closure', function (): void {
    OpenAiAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new OpenAiAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('openai agent fake with no predefined responses', function (): void {
    OpenAiAgent::fake();

    $response = (new OpenAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('openai agent fake records prompts', function (): void {
    OpenAiAgent::fake();

    (new OpenAiAgent)->prompt('Hello');

    OpenAiAgent::assertPrompted('Hello');
    OpenAiAgent::assertNotPrompted('Goodbye');
});

test('openai agent stream can be faked', function (): void {
    OpenAiAgent::fake(['Streamed response']);

    $response = (new OpenAiAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
