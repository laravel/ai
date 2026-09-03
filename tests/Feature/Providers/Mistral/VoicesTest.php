<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\Data\Voice;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('voices request hits the audio voices endpoint with a bearer token', function (): void {
    Http::fake(['*' => fakeMistralVoicesResponse()]);

    Audio::voices(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mistral.ai/v1/audio/voices?limit=100&offset=0&type=all'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('voices are mapped from the response with the slug as the voice id', function (): void {
    Http::fake(['*' => fakeMistralVoicesResponse()]);

    $response = Audio::voices(provider: 'mistral');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(Voice::class)
        ->and($response->first()->id)->toBe('en_paul_neutral')
        ->and($response->first()->name)->toBe('Paul - Neutral')
        ->and($response->first()->gender)->toBe('male')
        ->and($response->first()->languages)->toBe(['en_us'])
        ->and($response->meta->provider)->toBe('mistral');
});

test('voices can be found by id', function (): void {
    Http::fake(['*' => fakeMistralVoicesResponse()]);

    expect(Audio::voices(provider: 'mistral')->find('gb_jane_neutral')?->name)->toBe('Jane - Neutral');
});

test('voices throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    Audio::voices(provider: 'mistral');
})->throws(RequestException::class);

test('voices follows offset pagination until the full catalogue is collected', function (): void {
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        $voices = collect(range(0, 149))->map(fn (int $i): array => [
            'slug' => "voice_{$i}",
            'name' => "Voice {$i}",
            'gender' => 'female',
            'languages' => ['en_us'],
        ]);

        return Http::response([
            'items' => $voices->slice((int) $query['offset'], (int) $query['limit'])->values()->all(),
            'total' => 150,
            'page' => 1,
            'page_size' => (int) $query['limit'],
            'total_pages' => 2,
        ]);
    });

    $response = Audio::voices(provider: 'mistral');

    expect($response)->toHaveCount(150)
        ->and($response->first()->id)->toBe('voice_0')
        ->and($response->find('voice_149'))->not->toBeNull();

    Http::assertSentCount(2);
});

function fakeMistralVoicesResponse()
{
    return Http::response(['items' => [
        [
            'slug' => 'en_paul_neutral',
            'name' => 'Paul - Neutral',
            'gender' => 'male',
            'languages' => ['en_us'],
            'id' => 'c69964a6-ab8b-4f8a-9465-ec0925096ec8',
        ],
        [
            'slug' => 'gb_jane_neutral',
            'name' => 'Jane - Neutral',
            'gender' => 'female',
            'languages' => ['en_gb'],
            'id' => 'a0000000-0000-0000-0000-000000000000',
        ],
    ], 'total' => 2, 'page' => 1, 'page_size' => 100, 'total_pages' => 1]);
}
