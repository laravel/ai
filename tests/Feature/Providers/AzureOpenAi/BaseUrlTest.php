<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('classic endpoint uses deployment-specific path with api-version', function () {
    configureAzureProvider('https://my-resource.openai.azure.com', deployment: 'gpt-4o');

    Http::fake(['*' => fakeAzureResponse('Hello from Azure')]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->text)->toBe('Hello from Azure');

    azureAssertRequestSent('POST', 'https://my-resource.openai.azure.com/openai/deployments/gpt-4o/chat/completions');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api-version='));
});

test('v1 endpoint uses url directly without deployment path or api-version', function () {
    configureAzureProvider('https://my-resource.openai.azure.com/openai/v1', deployment: 'gpt-4o');

    Http::fake(['*' => fakeAzureResponse('Hello from Azure v1')]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->text)->toBe('Hello from Azure v1');

    azureAssertRequestSent('POST', 'https://my-resource.openai.azure.com/openai/v1/chat/completions');

    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'api-version'));
});

test('azure requests include api-version query parameter for classic endpoint', function () {
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
