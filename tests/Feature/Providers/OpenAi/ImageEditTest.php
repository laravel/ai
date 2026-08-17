<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiImageEditResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('edited-image'),
        ]],
    ]);
}

test('attachments send the request to the edits endpoint', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits'));
});

test('a base64 image is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'source-bytes'));
});

test('a remote image is fetched and sent as the image content', function (): void {
    Http::fake([
        'example.com/*' => Http::response('remote-bytes'),
        '*' => fakeOpenAiImageEditResponse(),
    ]);

    Image::of('Make it brighter')
        ->attachments([new RemoteImage('https://example.com/source.png', 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'remote-bytes'));
});

test('a local image is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
    file_put_contents($path, 'local-bytes');

    Image::of('Make it brighter')
        ->attachments([new LocalImage($path, 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'local-bytes'));

    unlink($path);
});

test('a stored image is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Storage::fake('images');
    Storage::disk('images')->put('source.png', 'stored-bytes');

    Image::of('Make it brighter')
        ->attachments([new StoredImage('source.png', 'images')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'stored-bytes'));
});

test('an uploaded file is sent as the image content', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
    file_put_contents($path, 'uploaded-bytes');

    Image::of('Make it brighter')
        ->attachments([new UploadedFile($path, 'source.png', 'image/png', null, true)])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'uploaded-bytes'));

    unlink($path);
});

test('a provider image cannot be used for edits', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new ProviderImage('file-123')])
        ->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(InvalidArgumentException::class, 'Unsupported image attachment type');

test('non gpt-image models send a single image field', function (): void {
    Http::fake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'dall-e-2');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="image"')
        && ! str_contains($request->body(), 'name="image[]"'));
});
