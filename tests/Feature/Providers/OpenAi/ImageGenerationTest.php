<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
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

test('image response includes usage tokens when returned by gpt-image', function () {
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

    $response = Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

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

    $response = Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    expect($response->usage->promptTokens)->toBe(70)
        ->and($response->usage->cacheReadInputTokens)->toBe(30)
        ->and($response->usage->completionTokens)->toBe(1024);
});

test('image response defaults to zero usage when not returned by dalle', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

    expect($response->usage->promptTokens)->toBe(0)
        ->and($response->usage->completionTokens)->toBe(0);
});

test('image generation request adds response_format b64_json for dall-e models', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ($body['response_format'] ?? null) === 'b64_json';
    });
});

test('image generation request omits response_format for gpt-image models', function () {
    Http::fake([
        '*' => fakeOpenAiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('image rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(RateLimitedException::class);

test('image overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(ProviderOverloadedException::class);

test('image http error response throws request exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid model',
            ],
        ], 400),
    ]);

    Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(RequestException::class);
