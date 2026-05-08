<?php

use Tests\Fixtures\Agents\NvidiaAgent;

test('nvidia agent can be faked', function () {
    NvidiaAgent::fake(['Test response']);

    $response = (new NvidiaAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('nvidia agent fake with closure', function () {
    NvidiaAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new NvidiaAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('nvidia agent fake with no predefined responses', function () {
    NvidiaAgent::fake();

    $response = (new NvidiaAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('nvidia agent fake records prompts', function () {
    NvidiaAgent::fake();

    (new NvidiaAgent)->prompt('Hello');

    NvidiaAgent::assertPrompted('Hello');
    NvidiaAgent::assertNotPrompted('Goodbye');
});

test('nvidia agent stream can be faked', function () {
    NvidiaAgent::fake(['Streamed response']);

    $response = (new NvidiaAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
