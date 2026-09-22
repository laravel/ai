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

function fakeGeminiImageInteraction(array $steps, array $usage = []): PromiseInterface
{
    return Http::response(array_filter([
        'id' => 'int_image',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => $usage ?: null,
    ]));
}

function geminiImageBlock(string $mimeType = 'image/png'): array
{
    return ['type' => 'image', 'mime_type' => $mimeType, 'data' => base64_encode('fake-image')];
}

function fakeGeminiImageResponse(string $mimeType = 'image/png'): PromiseInterface
{
    return fakeGeminiImageInteraction([[
        'type' => 'model_output',
        'content' => [geminiImageBlock($mimeType)],
    ]]);
}

test('image request posts the prompt to the interactions endpoint', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->url())->toEndWith('/interactions')
        ->and(sentRequest()->data())->toMatchArray(['model' => 'gemini-3.1-flash-image-preview'])
        ->and(sentRequest()->data()['input'][0])->toMatchArray(['type' => 'text', 'text' => 'A red apple']);
});

test('image request asks for an image response format', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['response_format'])->toMatchArray(['type' => 'image']);
});

test('image request includes default image size when quality not specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['response_format'])->toMatchArray(['image_size' => '1K']);
});

test('image request maps quality to image size', function (string $quality, string $expectedSize): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->quality($quality)->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['response_format'])->toMatchArray(['image_size' => $expectedSize]);
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

    expect(sentRequest()->data()['response_format'])->toMatchArray(['aspect_ratio' => '1:1']);
});

test('image request does not include aspect ratio when size not specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['response_format'])->not->toHaveKey('aspect_ratio');
});

test('image attachment is appended to the input blocks', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $attachment = new Base64Image(base64_encode('ref-image'), 'image/jpeg');

    Image::of('A red apple')->attachments([$attachment])->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['input'])->toHaveCount(2)
        ->and(sentRequest()->data()['input'][0])->toMatchArray(['text' => 'A red apple'])
        ->and(sentRequest()->data()['input'][1])->toMatchArray(['type' => 'image', 'mime_type' => 'image/jpeg']);
});

test('only image blocks are returned when the response mixes text and images', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([[
            'type' => 'model_output',
            'content' => [
                ['type' => 'text', 'text' => 'Here is your image:'],
                geminiImageBlock(),
            ],
        ]]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->mime)->toBe('image/png');
});

test('firstImage works when the response leads with a text block', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([[
            'type' => 'model_output',
            'content' => [
                ['type' => 'text', 'text' => 'Here is your image:'],
                geminiImageBlock(),
            ],
        ]]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->firstImage()->mime)->toBe('image/png')
        ->and($response->firstImage()->image)->toBe(base64_encode('fake-image'))
        ->and((string) $response)->toBe('fake-image');
});

test('images interleaved across several steps are all collected', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([
            ['type' => 'model_output', 'content' => [geminiImageBlock('image/png')]],
            ['type' => 'model_output', 'content' => [geminiImageBlock('image/jpeg')]],
        ]),
    ]);

    $response = Image::of('Two apples')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(2)
        ->and($response->images->last()->mime)->toBe('image/jpeg');
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

test('image response includes usage when returned', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction(
            [['type' => 'model_output', 'content' => [geminiImageBlock()]]],
            ['total_input_tokens' => 12, 'total_output_tokens' => 1290, 'total_tokens' => 1302],
        ),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->inputTokens)->toBe(12)
        ->and($response->usage->outputTokens)->toBe(1290);
});

test('image response defaults to zero usage when usage is absent', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->inputTokens)->toBe(0)
        ->and($response->usage->outputTokens)->toBe(0);
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

test('image response is empty when the interaction returns no steps', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'id' => 'int_blocked',
            'status' => 'failed',
            'steps' => [],
            'errors' => [['code' => 'BLOCKED_SAFETY', 'message' => 'Blocked for safety reasons.']],
        ]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(0);
});

test('image response is empty when the step carries no content', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([['type' => 'model_output']]),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->images)->toHaveCount(0);
});

test('a response format provider option is merged beneath the core format', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => fakeGeminiImageResponse()]);

    Image::of('A red apple')
        ->withProviderOptions(['response_format' => ['mime_type' => 'image/jpeg', 'type' => 'text']])
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect(sentRequest()->data()['response_format'])->toMatchArray([
        'mime_type' => 'image/jpeg',
        'type' => 'image',
    ]);
});

test('image response reports the image modality token counts', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction(
            [['type' => 'model_output', 'content' => [geminiImageBlock()]]],
            [
                'total_input_tokens' => 270,
                'total_output_tokens' => 1290,
                'total_tokens' => 1560,
                'input_tokens_by_modality' => [
                    ['modality' => 'TEXT', 'tokens' => 12],
                    ['modality' => 'IMAGE', 'tokens' => 258],
                ],
                'output_tokens_by_modality' => [
                    ['modality' => 'IMAGE', 'tokens' => 1290],
                ],
            ],
        ),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->imageInputTokens)->toBe(258)
        ->and($response->usage->imageOutputTokens)->toBe(1290);
});

test('image response leaves the image modality counts null when no details are returned', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
    ]);

    $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

    expect($response->usage->imageInputTokens)->toBeNull()
        ->and($response->usage->imageOutputTokens)->toBeNull();
});
