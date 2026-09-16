<?php

use Tests\Fixtures\Agents\ToolSearchAgent;

test('agents discover and call a deferred tool through hosted tool search', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = (new ToolSearchAgent)->prompt(
        'What is the secret authorization code for this session?',
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('ZEBRA-4417')
        ->and($response->toolCalls->pluck('name'))->toContain('SecretCodeGenerator');
})->with('tool-search-providers');
