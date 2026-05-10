<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;
use LogicException;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenRouterTranscriptionResponse(string $text = 'Hello, world!'): PromiseInterface
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'seconds' => 1.5,
            'total_tokens' => 30,
            'input_tokens' => 20,
            'output_tokens' => 10,
            'cost' => 0.000100,
        ],
    ]);
}

test('transcription request posts to correct endpoint as json', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://openrouter.ai/api/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'application/json');
    });
});

test('transcription request sends audio as base64 with format from mime type', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['input_audio']['data'] === base64_encode('fake-audio')
            && $body['input_audio']['format'] === 'mp3';
    });
});

test('transcription diarize throws logic exception without sending request', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    expect(fn () => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openrouter'))
        ->toThrow(LogicException::class, 'OpenRouter does not support diarized transcription');

    Http::assertNothingSent();
});

test('transcription maps audio mime types to openrouter format values', function (string $mimeType, string $expectedFormat) {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), $mimeType)->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) use ($expectedFormat) {
        return json_decode($request->body(), true)['input_audio']['format'] === $expectedFormat;
    });
})->with([
    'mp3 via audio/mpeg' => ['audio/mpeg', 'mp3'],
    'mp3 via audio/mp3' => ['audio/mp3', 'mp3'],
    'wav via audio/wav' => ['audio/wav', 'wav'],
    'wav via audio/x-wav' => ['audio/x-wav', 'wav'],
    'm4a via audio/m4a' => ['audio/m4a', 'm4a'],
    'm4a via audio/mp4' => ['audio/mp4', 'm4a'],
    'm4a via audio/x-m4a' => ['audio/x-m4a', 'm4a'],
    'ogg via audio/ogg' => ['audio/ogg', 'ogg'],
    'ogg via audio/ogg opus' => ['audio/ogg; codecs=opus', 'ogg'],
    'flac via audio/flac' => ['audio/flac', 'flac'],
    'flac via audio/x-flac' => ['audio/x-flac', 'flac'],
    'webm via audio/webm' => ['audio/webm', 'webm'],
    'aac via audio/aac' => ['audio/aac', 'aac'],
    'fallback to mp3 for unknown mime' => ['audio/unknown-format', 'mp3'],
]);

test('transcription request includes language when specified', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['language'] === 'fr';
    });
});

test('transcription request omits language when not specified', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return ! array_key_exists('language', json_decode($request->body(), true));
    });
});

test('transcription uses default model when none specified', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['model'] === 'openai/gpt-4o-transcribe';
    });
});

test('transcription response text is correctly parsed', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('openai/gpt-4o-transcribe');
});

test('transcription usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'seconds' => 2.0,
            'total_tokens' => 150,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost' => 0.0005,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(150);
});
