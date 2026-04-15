<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('azure text requests use the v1 responses endpoint', function () {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com', deployment: 'gpt-4o');

    Http::fake(['*' => fakeAzureResponse('Hello from Azure')]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->text)->toBe('Hello from Azure');

    azureAssertRequestSent('POST', 'https://my-resource.cognitiveservices.azure.com/openai/v1/responses');
});

test('azure requests do not include api-version query parameter', function () {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

    Http::fake(['*' => fakeAzureResponse()]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return ! str_contains($request->url(), 'api-version');
    });
});

test('azure requests use api-key header not bearer token', function () {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

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
