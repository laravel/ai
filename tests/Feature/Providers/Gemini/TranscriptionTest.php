<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

test('transcription request sends audio as inline data with correct mime type', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'gemini', model: 'gemini-3-flash-preview');

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $parts = $body['contents'][0]['parts'];

        return str_contains($request->url(), 'models/gemini-3-flash-preview:generateContent')
            && $parts[1]['inlineData']['mimeType'] === 'audio/mp3'
            && $parts[1]['inlineData']['data'] === base64_encode('fake-audio');
    });
});

test('transcription request includes language in prompt when specified', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'gemini', model: 'gemini-3-flash-preview');

    Http::assertSent(fn (Request $request) => str_contains(
        $request->data()['contents'][0]['parts'][0]['text'],
        'fr'
    ));
});

test('transcription response returns text with correct meta', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'gemini', model: 'gemini-3-flash-preview');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-3-flash-preview');
});

test('transcription uses default model when none specified', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'gemini');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-3-flash-preview:generateContent'));
});

test('diarized transcription request sends json schema in generation config', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3-flash-preview');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return isset($body['generationConfig']['responseMimeType'])
            && $body['generationConfig']['responseMimeType'] === 'application/json'
            && isset($body['generationConfig']['responseSchema']['properties']['segments']);
    });
});

test('diarized transcription response returns text and segments', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3-flash-preview');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->text)->toBe('Hello')
        ->and($response->segments[0]->startSeconds)->toBe(0.0)
        ->and($response->segments[0]->endSeconds)->toBe(2.0)
        ->and($response->segments[1]->text)->toBe('world')
        ->and($response->segments[1]->startSeconds)->toBe(2.0)
        ->and($response->segments[1]->endSeconds)->toBe(4.0);
});

function fakeGeminiTranscriptionResponse(): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'candidates' => [[
            'content' => [
                'parts' => [['text' => 'Hello world']],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
    ]);
}

function fakeGeminiDiarizedTranscriptionResponse(): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'candidates' => [[
            'content' => [
                'parts' => [['text' => json_encode([
                    'transcript' => 'Hello world',
                    'segments' => [
                        ['text' => 'Hello', 'start_time' => '0:00', 'end_time' => '0:02'],
                        ['text' => 'world', 'start_time' => '0:02', 'end_time' => '0:04'],
                    ],
                ])]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
    ]);
}
