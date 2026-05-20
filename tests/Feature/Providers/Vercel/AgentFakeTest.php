<?php

use Tests\Fixtures\Agents\VercelAgent;

test('vercel agent can be faked', function () {
    VercelAgent::fake(['Test response']);

    $response = (new VercelAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('vercel agent fake with closure', function () {
    VercelAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

    $response = (new VercelAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('vercel agent fake records prompts', function () {
    VercelAgent::fake();

    (new VercelAgent)->prompt('Hello');

    VercelAgent::assertPrompted('Hello');
    VercelAgent::assertNotPrompted('Goodbye');
});

test('vercel agent stream can be faked', function () {
    VercelAgent::fake(['Streamed response']);

    $response = (new VercelAgent)->stream('Hello');
    $response->each(fn () => true);

    expect($response->text)->toBe('Streamed response');
});
