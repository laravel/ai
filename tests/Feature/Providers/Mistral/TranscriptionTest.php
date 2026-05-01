<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('transcription request posts to correct endpoint', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');
    });
});

test('transcription response text is correctly parsed', function () {
    Http::fake(['*' => fakeTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('mistral');
});

test('transcription includes model in request', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request) {
        return str_contains($request->body(), 'voxtral-mini-latest');
    });
});

test('transcription sends language when provided', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request) {
        return str_contains($request->body(), 'language')
            && str_contains($request->body(), 'en');
    });
});

test('transcription sends bearer token', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('transcription usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50);
});

function fakeTranscriptionResponse(string $text = 'Hello, world!')
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
