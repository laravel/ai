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
