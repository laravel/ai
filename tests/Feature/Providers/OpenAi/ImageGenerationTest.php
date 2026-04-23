<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiImageResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('fake-image'),
        ]],
    ]);
}

test('image request does not include quality when not specified', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-2');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'dall-e-2'
            && ! array_key_exists('quality', $body);
    });
});

test('image request does not include moderation for non gpt-image models', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'dall-e-3'
            && ! array_key_exists('moderation', $body);
    });
});

test('image request includes moderation low for gpt-image models', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-image-1'
            && $body['moderation'] === 'low';
    });
});

test('image request includes quality when explicitly specified', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->quality('high')->generate(provider: 'openai', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['quality'] === 'high'
            && ! array_key_exists('moderation', $body);
    });
});

test('image request includes size when specified', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['size'] === '1024x1024';
    });
});

test('image request does not include size when not specified', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('size', $body);
    });
});
