<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\QueuedImagePrompt;
use Laravel\Ai\Providers\Provider;

beforeEach(function (): void {
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);
});

test('flat provider options are sent on the image request', function (): void {
    Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

    Image::of('A red apple')
        ->withProviderOptions(['background' => 'transparent'])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
});

test('provider options may not override the core image request payload', function (): void {
    Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

    Image::of('A red apple')
        ->withProviderOptions(['model' => 'hijacked', 'prompt' => 'hijacked'])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-image-1' && $body['prompt'] === 'A red apple';
    });
});

test('closure provider options receive the resolved image provider', function (): void {
    Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

    $seen = [];

    Image::of('A red apple')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['background' => 'transparent'];
        })
        ->generate(provider: 'openai', model: 'gpt-image-1');

    expect($seen)->toBe(['openai']);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
});

test('flat provider options are recorded on the queued image prompt fake', function (): void {
    Image::fake();

    Image::of('A red apple')
        ->withProviderOptions(['background' => 'transparent'])
        ->queue(provider: 'openai');

    Image::assertQueued(
        fn (QueuedImagePrompt $prompt): bool => $prompt->providerOptions === ['background' => 'transparent'],
    );
});
