<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
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

test('image request maps quality to image size', function (string $quality, string $expectedSize) {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality($quality)->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) use ($expectedSize) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === $expectedSize;
    });
})->with([
    'low maps to 1K' => ['low', '1K'],
    'medium maps to 2K' => ['medium', '2K'],
    'high maps to 4K' => ['high', '4K'],
]);

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

test('image attachment is appended to contents parts', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $attachment = new Base64Image(base64_encode('ref-image'), 'image/jpeg');

    Image::of('A red apple')->attachments([$attachment])->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $parts = data_get($body, 'contents.0.parts');

        return count($parts) === 2
            && $parts[0]['text'] === 'A red apple'
            && data_get($parts[1], 'inlineData.mimeType') === 'image/jpeg';
    });
});

test('only inlineData parts are returned when response contains mixed text and image parts', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Here is your image:'],
                        [
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode('fake-image'),
                            ],
                        ],
                    ],
                ],
            ]],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->mime)->toBe('image/png');
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

test('image response includes usage metadata when returned', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'inlineData' => [
                            'mimeType' => 'image/png',
                            'data' => base64_encode('fake-image'),
                        ],
                    ]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 12,
                'candidatesTokenCount' => 1290,
                'totalTokenCount' => 1302,
            ],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(1290);
});

test('image response defaults to zero usage when usage metadata absent', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->promptTokens)->toBe(0)
        ->and($response->usage->completionTokens)->toBe(0);
});
