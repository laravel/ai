<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeTranscriptionResponse(string $text = 'Hello, world!')
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'input_tokens' => 10,
            'total_tokens' => 15,
        ],
    ]);
}

test('transcription request posts to correct endpoint', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');
    });
});

test('transcription includes model in request', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(function (Request $request) {
        return str_contains($request->body(), 'gpt-4o-transcribe');
    });
});

test('transcription strips diarize suffix from model when diarize is off', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

    Http::assertSent(function (Request $request) {
        return str_contains($request->body(), 'gpt-4o-transcribe')
            && ! str_contains($request->body(), 'gpt-4o-transcribe-diarize');
    });
});

test('transcription response text is correctly parsed', function () {
    Http::fake(['*' => fakeTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('openai');
});

test('transcription usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'input_tokens' => 100,
            'total_tokens' => 150,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(150);
});

test('transcription sends language when provided', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'openai');

    Http::assertSent(function (Request $request) {
        return str_contains($request->body(), 'language')
            && str_contains($request->body(), 'en');
    });
});

test('transcription request sends bearer token', function () {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});
