<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
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

test('image request includes model, prompt, and b64_json response format', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'grok-imagine-image'
            && $body['prompt'] === 'A red apple'
            && $body['response_format'] === 'b64_json'
            && ! array_key_exists('size', $body)
            && ! array_key_exists('quality', $body);
    });
});

test('omits size when provided because xAI does not support it', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'xai', model: 'grok-imagine-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('size', $body);
    });
});

test('omits quality when provided because xAI does not support it', function () {
    Http::fake([
        '*' => fakeXaiImageResponse(),
    ]);

    Image::of('A red apple')->quality('high')->generate(provider: 'xai', model: 'grok-imagine-image');

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
        ->and($response->images->first()->mime)->toBe('image/jpeg')
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

test('image rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.x.ai/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
})->throws(RateLimitedException::class);

test('image overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.x.ai/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
})->throws(ProviderOverloadedException::class);

test('image http error response throws request exception', function () {
    Http::fake([
        'api.x.ai/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid model',
            ],
        ], 400),
    ]);

    Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
})->throws(ProviderRequestException::class);
