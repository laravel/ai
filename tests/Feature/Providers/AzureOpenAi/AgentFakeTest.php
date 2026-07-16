<?php

use Tests\Fixtures\Agents\AzureAgent;

test('azure agent can be faked', function (): void {
    AzureAgent::fake(['Test response']);

    $response = (new AzureAgent)->prompt('Hello');

    expect($response->text)->toBe('Test response');
});

test('azure agent fake with closure', function (): void {
    AzureAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

    $response = (new AzureAgent)->prompt('Hello world');

    expect($response->text)->toBe('Echo: Hello world');
});

test('azure agent fake with no predefined responses', function (): void {
    AzureAgent::fake();

    $response = (new AzureAgent)->prompt('Hello');

    expect($response->text)->toBe('Fake response for prompt: Hello');
});

test('azure agent fake records prompts', function (): void {
    AzureAgent::fake();

    (new AzureAgent)->prompt('Hello');

    AzureAgent::assertPrompted('Hello');
    AzureAgent::assertNotPrompted('Goodbye');
});

test('azure agent stream can be faked', function (): void {
    AzureAgent::fake(['Streamed response']);

    $response = (new AzureAgent)->stream('Hello');
    $response->each(fn (): true => true);

    expect($response->text)->toBe('Streamed response');
});
