<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Image;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenRouterImageResponse(string $mimeType = 'image/png', string $content = 'fake-image'): PromiseInterface
{
    return Http::response([
        'choices' => [[
            'message' => [
                'images' => [[
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,".base64_encode($content),
                    ],
                ]],
            ],
        ]],
        'model' => 'google/gemini-2.5-flash-image',
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
        ],
    ]);
}

test('image request includes model, messages, and modalities', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'chat/completions')
            && $body['model'] === 'google/gemini-2.5-flash-image'
            && $body['modalities'] === ['image']
            && $body['messages'][0]['role'] === 'user'
            && $body['messages'][0]['content'] === 'A blue circle';
    });
});

test('image request maps size to image_config aspect_ratio', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')->landscape()->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'image_config.aspect_ratio') === '3:2'
            && ! array_key_exists('image_size', data_get($body, 'image_config', []));
    });
});

test('image request maps quality to image_config image_size', function (string $quality, string $expectedSize) {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')->quality($quality)->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) use ($expectedSize) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'image_config.image_size') === $expectedSize;
    });
})->with([
    'low maps to 1K' => ['low', '1K'],
    'medium maps to 2K' => ['medium', '2K'],
    'high maps to 4K' => ['high', '4K'],
]);

test('image request omits image_config when no size or quality is given', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('image_config', $body);
    });
});

test('image response is correctly parsed', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse('image/png', 'fake-image')]);

    $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->image)->toBe(base64_encode('fake-image'))
        ->and($response->images->first()->mime)->toBe('image/png')
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('google/gemini-2.5-flash-image');
});

test('usage tokens are parsed from response', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(20);
});

test('multiple images in response are all returned', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'images' => [
                        ['image_url' => ['url' => 'data:image/png;base64,'.base64_encode('first')]],
                        ['image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode('second')]],
                    ],
                ],
            ]],
        ]),
    ]);

    $response = Image::of('Two images')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(2)
        ->and($response->images[0]->image)->toBe(base64_encode('first'))
        ->and($response->images[0]->mime)->toBe('image/png')
        ->and($response->images[1]->image)->toBe(base64_encode('second'))
        ->and($response->images[1]->mime)->toBe('image/jpeg');
});

test('empty images collection returned when response contains no images', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => 'I cannot generate that image.'],
            ]],
        ]),
    ]);

    $response = Image::of('A prompt')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(0);
});

test('attachments are sent as image_url content parts', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    $attachment = new Base64Image(base64_encode('source-image'), 'image/jpeg');

    Image::of('Edit this image')->attachments([$attachment])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][0]['content'];

        return is_array($content)
            && $content[0]['type'] === 'text'
            && $content[0]['text'] === 'Edit this image'
            && $content[1]['type'] === 'image_url'
            && str_starts_with($content[1]['image_url']['url'], 'data:image/jpeg;base64,');
    });
});

test('remote image attachments are passed as url without downloading', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    $attachment = new RemoteImage('https://example.com/photo.jpg', 'image/jpeg');

    Image::of('Edit this image')->attachments([$attachment])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][0]['content'];

        return is_array($content)
            && $content[1]['type'] === 'image_url'
            && $content[1]['image_url']['url'] === 'https://example.com/photo.jpg';
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('image rate limit response throws rate limited exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(RateLimitedException::class);

test('image overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(ProviderOverloadedException::class);

test('image http error response throws request exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid model',
            ],
        ], 400),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(ProviderRequestException::class);
