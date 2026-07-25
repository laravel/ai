<?php

use Tests\Fixtures\Agents\DeepSeekAgent;

test('deepseek agent can be faked', function (): void {
    DeepSeekAgent::fake(['Test response']);

    $response = (new DeepSeekAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('deepseek agent fake with closure', function (): void {
    DeepSeekAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new DeepSeekAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('deepseek agent fake with no predefined responses', function (): void {
    DeepSeekAgent::fake();

    $response = (new DeepSeekAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('deepseek agent fake records prompts', function (): void {
    DeepSeekAgent::fake();

    (new DeepSeekAgent)->prompt('Hello');

    DeepSeekAgent::assertPrompted('Hello');
    DeepSeekAgent::assertNotPrompted('Goodbye');
});

test('deepseek agent stream can be faked', function (): void {
    DeepSeekAgent::fake(['Streamed response']);

    $response = (new DeepSeekAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
