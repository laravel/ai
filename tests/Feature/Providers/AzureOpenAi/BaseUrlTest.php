<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('azure text requests use deployment-specific path', function () {
    configureAzureProvider('https://my-resource.openai.azure.com', deployment: 'gpt-4o');

    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from Azure',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->text)->toBe('Hello from Azure');

    Http::assertSentCount(1);
    azureAssertRequestSent('POST', 'https://my-resource.openai.azure.com/openai/deployments/gpt-4o/chat/completions');
});

test('azure requests include api-version query parameter', function () {
    configureAzureProvider('https://my-resource.openai.azure.com', '2024-10-21');

    Http::fake(['*' => fakeAzureResponse()]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'api-version=2024-10-21');
    });
});

test('azure requests use api-key header not bearer token', function () {
    configureAzureProvider('https://my-resource.openai.azure.com');

    Http::fake(['*' => fakeAzureResponse()]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('api-key', 'test-key')
            && ! $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

function configureAzureProvider(?string $url = null, ?string $apiVersion = null, string $deployment = 'gpt-4o'): void
{
    config(['ai.providers.azure' => array_filter([
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => $url,
        'api_version' => $apiVersion ?? '2024-10-21',
        'deployment' => $deployment,
    ])]);
}

function azureAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(function (Request $request) use ($method, $url) {
        $requestUrl = strtok($request->url(), '?');

        return $request->method() === $method
            && $requestUrl === $url;
    });
}
