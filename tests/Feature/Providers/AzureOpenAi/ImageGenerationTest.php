<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]]);
});

function fakeAzureImageResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('fake-image'),
        ]],
    ]);
}

test('image request uses correct deployment and url', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://test-resource.openai.azure.com/openai/v1/images/generations'
            && $body['model'] === 'gpt-image-1';
    });
});

test('image request does not include quality when not specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('quality', $body);
    });
});

test('image request includes quality when explicitly specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->quality('high')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['quality'] === 'high';
    });
});

test('image request includes size when specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['size'] === '1024x1024';
    });
});

test('image request does not include size when not specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('size', $body);
    });
});

test('image request always includes moderation low', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ($body['moderation'] ?? null) === 'low';
    });
});

test('image edit request hits deployment-scoped endpoint with multipart attachment', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('Add a leaf to the apple')
        ->attachments([new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')])
        ->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        return $request->url() === 'https://test-resource.openai.azure.com/openai/deployments/gpt-image-1/images/edits?api-version=2025-04-01-preview'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
            && str_contains($body, 'name="image[]"')
            && str_contains($body, 'name="model"')
            && str_contains($body, 'gpt-image-1')
            && str_contains($body, 'Add a leaf to the apple')
            && str_contains($body, 'name="moderation"')
            && str_contains($body, "\r\nlow\r\n");
    });
});

test('image edit request honors configured api version', function () {
    config(['ai.providers.azure.api_version' => '2025-09-01-preview']);

    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('Add a leaf to the apple')
        ->attachments([new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')])
        ->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api-version=2025-09-01-preview'));
});

test('image generation request omits response_format', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('image response includes usage tokens', function () {
    Http::fake([
        '*' => Http::response([
            'data' => [[
                'b64_json' => base64_encode('fake-image'),
            ]],
            'usage' => [
                'input_tokens' => 41,
                'output_tokens' => 1024,
                'input_tokens_details' => [
                    'text_tokens' => 41,
                    'image_tokens' => 0,
                ],
            ],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    expect($response->usage->promptTokens)->toBe(41)
        ->and($response->usage->completionTokens)->toBe(1024);
});

test('image response subtracts cached tokens from prompt tokens', function () {
    Http::fake([
        '*' => Http::response([
            'data' => [[
                'b64_json' => base64_encode('fake-image'),
            ]],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 1024,
                'input_tokens_details' => [
                    'cached_tokens' => 30,
                    'text_tokens' => 70,
                ],
            ],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    expect($response->usage->promptTokens)->toBe(70)
        ->and($response->usage->cacheReadInputTokens)->toBe(30)
        ->and($response->usage->completionTokens)->toBe(1024);
});

test('image response defaults to zero usage when not returned', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    expect($response->usage->promptTokens)->toBe(0)
        ->and($response->usage->completionTokens)->toBe(0);
});

test('image edit reads bytes from configured storage disk for StoredImage attachments', function () {
    Storage::fake('images');
    Storage::disk('images')->put('apple.png', 'fake-stored-bytes');

    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('Add a leaf to the apple')
        ->attachments([new StoredImage('apple.png', 'images')])
        ->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        return str_contains($request->url(), '/openai/deployments/gpt-image-1/images/edits')
            && str_contains($request->url(), 'api-version=2025-04-01-preview')
            && str_contains($body, 'name="image[]"')
            && str_contains($body, 'fake-stored-bytes');
    });
});

test('default image model falls back to gpt-image-1', function () {
    config(['ai.providers.azure.image_deployment' => null]);

    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-image-1';
    });
});
