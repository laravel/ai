<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\Data\Voice;

beforeEach(function (): void {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
    ]]);
});

test('voices request hits the voices endpoint with the api key header', function (): void {
    Http::fake(['*' => fakeElevenVoicesResponse()]);

    Audio::voices(provider: 'eleven');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/voices'
        && $request->method() === 'GET'
        && $request->hasHeader('xi-api-key', 'test-key'));
});

test('voices are mapped from the response', function (): void {
    Http::fake(['*' => fakeElevenVoicesResponse()]);

    $response = Audio::voices(provider: 'eleven');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(Voice::class)
        ->and($response->first()->id)->toBe('21m00Tcm4TlvDq8ikWAM')
        ->and($response->first()->name)->toBe('Rachel')
        ->and($response->first()->gender)->toBe('female')
        ->and($response->first()->languages)->toBe(['en'])
        ->and($response->meta->provider)->toBe('eleven');
});

test('voices without labels or verified languages map to null gender and empty languages', function (): void {
    Http::fake(['*' => Http::response(['voices' => [
        ['voice_id' => 'cloned-voice-id', 'name' => 'My Clone'],
    ]])]);

    $voice = Audio::voices(provider: 'eleven')->first();

    expect($voice->gender)->toBeNull()
        ->and($voice->languages)->toBe([]);
});

test('voices can be found by id', function (): void {
    Http::fake(['*' => fakeElevenVoicesResponse()]);

    $response = Audio::voices(provider: 'eleven');

    expect($response->find('onwK4e9ZLuTAKqWW03F9')?->name)->toBe('Daniel')
        ->and($response->find('missing'))->toBeNull();
});

test('voices throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Audio::voices(provider: 'eleven');
})->throws(RequestException::class);

function fakeElevenVoicesResponse()
{
    return Http::response(['voices' => [
        [
            'voice_id' => '21m00Tcm4TlvDq8ikWAM',
            'name' => 'Rachel',
            'labels' => ['accent' => 'American', 'gender' => 'female'],
            'verified_languages' => [
                ['language' => 'en', 'model_id' => 'eleven_multilingual_v2', 'locale' => 'en-US'],
            ],
        ],
        [
            'voice_id' => 'onwK4e9ZLuTAKqWW03F9',
            'name' => 'Daniel',
            'labels' => ['gender' => 'male'],
            'verified_languages' => [
                ['language' => 'en'],
                ['language' => 'nl'],
            ],
        ],
    ]]);
}
