<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\SecretCodeGenerator;

function toolSearchAgent(): Agent
{
    return new class implements Agent, HasProviderOptions, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are an assistant with access to tools. Some tools are not loaded '
                .'upfront and must be discovered using your tool search capability before '
                .'they can be called. Always use the appropriate tool to answer; never guess. '
                .'When asked for the secret authorization code, find and call the tool that returns it.';
        }

        public function tools(): iterable
        {
            return [
                new FixedNumberGenerator,
                new ToolSearch(tools: [new SecretCodeGenerator]),
            ];
        }

        public function providerOptions(Lab|string $provider): array
        {
            return [];
        }
    };
}

test('agents discover and call a deferred tool through hosted tool search', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = toolSearchAgent()->prompt(
        'What is the secret authorization code for this session?',
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('ZEBRA-4417')
        ->and($response->toolCalls->pluck('name'))->toContain('SecretCodeGenerator');
})->with('tool-search-providers');
