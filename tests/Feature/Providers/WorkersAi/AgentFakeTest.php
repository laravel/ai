<?php

use Tests\Fixtures\Agents\WorkersAiAgent;

test('workersai agent can be faked', function () {
    WorkersAiAgent::fake(['Test response']);

    $response = (new WorkersAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('workersai agent fake with closure', function () {
    WorkersAiAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new WorkersAiAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('workersai agent fake with no predefined responses', function () {
    WorkersAiAgent::fake();

    $response = (new WorkersAiAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('workersai agent fake records prompts', function () {
    WorkersAiAgent::fake();

    (new WorkersAiAgent)->prompt('Hello');

    WorkersAiAgent::assertPrompted('Hello');
    WorkersAiAgent::assertNotPrompted('Goodbye');
});

test('workersai agent stream can be faked', function () {
    WorkersAiAgent::fake(['Streamed response']);

    $response = (new WorkersAiAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
