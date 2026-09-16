<?php

use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Ai\agent;

test('agents answer using the hosted code execution tool', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent(
        'Use the code execution tool to compute the answer. Reply with the hex digest only.',
        tools: [new CodeExecution],
    )->prompt(
        'What is the SHA-256 hex digest of the exact ASCII string laravel-ai-code-execution?',
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('914b82ea0d8d6269b1eb6a8ea80c929ea3035c7286daa71bfafb051a16fd3a9e');
})->with('code-execution-providers');

test('agents stream provider tool events while using the hosted code execution tool', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent(
        'Use the code execution tool to compute the answer. Reply with the hex digest only.',
        tools: [new CodeExecution],
    )->stream(
        'What is the SHA-256 hex digest of the exact ASCII string laravel-ai-code-execution?',
        provider: $provider,
        model: $model,
    );

    $providerEvents = [];
    $text = '';

    foreach ($response as $event) {
        if ($event instanceof ProviderToolEvent) {
            $providerEvents[] = $event;
        } elseif ($event instanceof TextDelta) {
            $text .= $event->delta;
        }
    }

    $streamedCode = array_filter($providerEvents, fn (ProviderToolEvent $event): bool => str_contains(json_encode($event->data), 'sha256'));

    expect($streamedCode)->not->toBeEmpty()
        ->and($providerEvents[0]->provider)->toBe($provider)
        ->and($text)->toContain('914b82ea0d8d6269b1eb6a8ea80c929ea3035c7286daa71bfafb051a16fd3a9e');
})->with('code-execution-providers');
