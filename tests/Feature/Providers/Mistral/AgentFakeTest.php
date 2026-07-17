<?php

use Tests\Fixtures\Agents\MistralAgent;

test('mistral agent can be faked', function (): void {
    MistralAgent::fake(['Test response']);

    $response = (new MistralAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('mistral agent fake with closure', function (): void {
    MistralAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new MistralAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('mistral agent fake with no predefined responses', function (): void {
    MistralAgent::fake();

    $response = (new MistralAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('mistral agent fake records prompts', function (): void {
    MistralAgent::fake();

    (new MistralAgent)->prompt('Hello');

    MistralAgent::assertPrompted('Hello');
    MistralAgent::assertNotPrompted('Goodbye');
});

test('mistral agent stream can be faked', function (): void {
    MistralAgent::fake(['Streamed response']);

    $response = (new MistralAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
