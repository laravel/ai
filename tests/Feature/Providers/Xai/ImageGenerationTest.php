<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

function fakeXaiImageResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('fake-image'),
        ]],
    ]);
}

test('image request includes model and prompt', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'grok-imagine-image'
            && $body['prompt'] === 'A red apple';
    });
});

test('image request always uses b64_json response format', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['response_format'] === 'b64_json';
    });
});

test('image request does not include size', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('size', $body);
    });
});

test('image request does not include quality', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('quality', $body);
    });
});

test('image response is correctly parsed', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->image)->toBe(base64_encode('fake-image'))
        ->and($response->meta->provider)->toBe('xai');
});

test('request sends bearer token authorization', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});
