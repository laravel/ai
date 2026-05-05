<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

test('image request uses correct deployment', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'dall-e-2');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'dall-e-2';
    });
});

test('image request does not include quality when not specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'dall-e-2');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('quality', $body);
    });
});

test('image request includes quality when explicitly specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->quality('high')->generate(provider: 'azure', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['quality'] === 'high';
    });
});

test('image request includes size when specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'azure', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['size'] === '1024x1024';
    });
});

test('image request does not include size when not specified', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('size', $body);
    });
});

test('image request includes moderation for gpt-image models', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ($body['moderation'] ?? null) === 'low';
    });
});

test('image request does not include moderation for non gpt-image models', function () {
    Http::fake([
        '*' => fakeAzureImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'azure', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('moderation', $body);
    });
});
