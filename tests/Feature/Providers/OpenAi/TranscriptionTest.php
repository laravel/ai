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

test('transcription sends prompt when context is provided', function () {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->context('Laravel Forge and Vapor')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && str_contains($request->body(), 'prompt')
            && str_contains($request->body(), 'Laravel Forge and Vapor');
    });
});

test('transcription context is not sent for diarized transcriptions', function () {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->context('Laravel Forge and Vapor')
        ->diarize()
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');
})->throws(LogicException::class, 'OpenAI does not support transcription context for diarized transcriptions.');

function fakeOpenAiTranscriptionResponse()
{
    return Http::response([
        'text' => 'Hello world',
        'usage' => [
            'input_tokens' => 10,
            'total_tokens' => 15,
        ],
    ]);
}
