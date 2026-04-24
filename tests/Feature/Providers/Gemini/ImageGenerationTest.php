<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;

beforeEach(function () {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

function fakeGeminiImageResponse(string $mimeType = 'image/png'): PromiseInterface
{
    return Http::response([
        'candidates' => [[
            'content' => [
                'parts' => [[
                    'inlineData' => [
                        'mimeType' => $mimeType,
                        'data' => base64_encode('fake-image'),
                    ],
                ]],
            ],
        ]],
    ]);
}

test('image request includes prompt in contents', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'contents.0.role') === 'user'
            && data_get($body, 'contents.0.parts.0.text') === 'A red apple';
    });
});

test('image request includes IMAGE and TEXT response modalities', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.responseModalities') === ['IMAGE', 'TEXT'];
    });
});

test('image request includes default image size when quality not specified', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === '1K';
    });
});

test('image request maps low quality to 1K image size', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality('low')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === '1K';
    });
});

test('image request maps medium quality to 2K image size', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality('medium')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === '2K';
    });
});

test('image request maps high quality to 4K image size', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality('high')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === '4K';
    });
});

test('image request maps size to aspect ratio', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.aspectRatio') === '1:1';
    });
});

test('image request does not include aspect ratio when size not specified', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('aspectRatio', data_get($body, 'generationConfig.imageConfig', []));
    });
});

test('image response is correctly parsed', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse('image/png'),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->image)->toBe(base64_encode('fake-image'))
        ->and($response->images->first()->mime)->toBe('image/png')
        ->and($response->meta->provider)->toBe('gemini');
});

test('request sends x-goog-api-key header', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('x-goog-api-key', 'test-key');
    });
});
