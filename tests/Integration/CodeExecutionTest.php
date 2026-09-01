<?php

use Laravel\Ai\Providers\Tools\CodeExecution;

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
