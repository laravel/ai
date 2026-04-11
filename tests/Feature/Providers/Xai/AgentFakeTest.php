<?php

use Tests\Feature\Agents\XaiAgent;

test('xai agent can be faked', function () {
    XaiAgent::fake(['Test response']);

    $response = (new XaiAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('xai agent fake with closure', function () {
    XaiAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new XaiAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('xai agent fake with no predefined responses', function () {
    XaiAgent::fake();

    $response = (new XaiAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('xai agent fake records prompts', function () {
    XaiAgent::fake();

    (new XaiAgent)->prompt('Hello');

    XaiAgent::assertPrompted('Hello');
    XaiAgent::assertNotPrompted('Goodbye');
});

test('xai agent stream can be faked', function () {
    XaiAgent::fake(['Streamed response']);

    $response = (new XaiAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
