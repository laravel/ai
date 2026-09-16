<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Image;

beforeEach(function (): void {
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

test('image request includes prompt in contents', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'contents.0.role') === 'user'
            && data_get($body, 'contents.0.parts.0.text') === 'A red apple';
    });
});

test('image request includes IMAGE and TEXT response modalities', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.responseModalities') === ['IMAGE', 'TEXT'];
    });
});

test('image request includes default image size when quality not specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === '1K';
    });
});

test('image request maps quality to image size', function (string $quality, string $expectedSize): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality($quality)->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request) use ($expectedSize): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.imageSize') === $expectedSize;
    });
})->with([
    'low maps to 1K' => ['low', '1K'],
    'medium maps to 2K' => ['medium', '2K'],
    'high maps to 4K' => ['high', '4K'],
]);

test('image request maps size to aspect ratio', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->square()->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'generationConfig.imageConfig.aspectRatio') === '1:1';
    });
});

test('image request does not include aspect ratio when size not specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('aspectRatio', data_get($body, 'generationConfig.imageConfig', []));
    });
});

test('image attachment is appended to contents parts', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $attachment = new Base64Image(base64_encode('ref-image'), 'image/jpeg');

    Image::of('A red apple')->attachments([$attachment])->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $parts = data_get($body, 'contents.0.parts');

        return count($parts) === 2
            && $parts[0]['text'] === 'A red apple'
            && data_get($parts[1], 'inlineData.mimeType') === 'image/jpeg';
    });
});

test('only inlineData parts are returned when response contains mixed text and image parts', function (): void {
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

test('firstImage works when response leads with a text part before the inlineData', function (): void {
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

    expect($response->firstImage()->mime)->toBe('image/png')
        ->and($response->firstImage()->image)->toBe(base64_encode('fake-image'))
        ->and((string) $response)->toBe('fake-image');
});

test('image response is correctly parsed', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse('image/png'),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->image)->toBe(base64_encode('fake-image'))
        ->and($response->images->first()->mime)->toBe('image/png')
        ->and($response->meta->provider)->toBe('gemini');
});

test('request sends x-goog-api-key header', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key'));
});

test('image response includes usage metadata when returned', function (): void {
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

test('image response defaults to zero usage when usage metadata absent', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->promptTokens)->toBe(0)
        ->and($response->usage->completionTokens)->toBe(0);
});

test('image rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Resource has been exhausted',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
})->throws(RateLimitedException::class);

test('image overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 503,
                'message' => 'The model is overloaded. Please try again later.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
})->throws(ProviderOverloadedException::class);

test('image http error response throws request exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'Invalid value at prompt',
                'status' => 'INVALID_ARGUMENT',
            ],
        ], 400),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
})->throws(RequestException::class);

test('image response is empty when prompt is blocked and candidates array is empty', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [],
            'promptFeedback' => [
                'blockReason' => 'SAFETY',
            ],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(0);
});

test('image response is empty when candidate is blocked with no content parts', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'PROHIBITED_CONTENT',
            ]],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(0);
});

test('nested generation config provider options are merged beneath the core config', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => fakeGeminiImageResponse()]);

    Image::of('A red apple')
        ->withProviderOptions(['generationConfig' => ['temperature' => 0.1, 'responseModalities' => ['TEXT']]])
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    Http::assertSent(function (Request $request): bool {
        $config = json_decode($request->body(), true)['generationConfig'];

        return $config['temperature'] === 0.1 && $config['responseModalities'] === ['IMAGE', 'TEXT'];
    });
});
