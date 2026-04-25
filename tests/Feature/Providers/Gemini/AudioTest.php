<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Gateway\Enums\GeminiVoice;

beforeEach(function () {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

test('audio request includes model, prompt text, and voice name', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice(GeminiVoice::KORE)
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return str_contains($request->url(), 'models/gemini-2.5-flash-preview-tts:generateContent')
            && $body['contents'][0]['parts'][0]['text'] === 'Hello world'
            && $body['generationConfig']['responseModalities'] === ['AUDIO']
            && $body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore';
    });
});

test('audio request resolves default voice aliases', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');
    Audio::of('Hello world')->male()->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore');
    Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck');
});

test('audio instructions are prepended to the prompt instead of sent in speech config', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Have a wonderful day!')
        ->voice(GeminiVoice::KORE)
        ->instructions('Say cheerfully:')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $speechConfig = $body['generationConfig']['speechConfig'];

        return $body['contents'][0]['parts'][0]['text'] === "Say cheerfully:\n\nHave a wonderful day!"
            && ! isset($speechConfig['instructions'])
            && ! isset($speechConfig['voiceConfig']['instructions'])
            && ! isset($speechConfig['voiceConfig']['prebuiltVoiceConfig']['instructions']);
    });
});


test('audio response is wrapped as wav with correct meta', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse("\x01\x00\x02\x00"),
    ]);

    $response = Audio::of('Hello world')
        ->voice(GeminiVoice::KORE)
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    expect($response->mimeType())->toBe('audio/wav')
        ->and(substr($response->content(), 0, 4))->toBe('RIFF')
        ->and(substr($response->content(), 8, 4))->toBe('WAVE')
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-2.5-flash-preview-tts');
});


test('audio request passes custom voice name through unchanged', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('my-custom-voice')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'my-custom-voice');
});

test('audio uses default model when none specified', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->voice(GeminiVoice::KORE)->generate(provider: 'gemini');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-2.5-flash-preview-tts:generateContent'));
});

function fakeGeminiAudioResponse(string $pcm = "\x00\x00"): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'candidates' => [[
            'content' => [
                'parts' => [[
                    'inlineData' => [
                        'data' => base64_encode($pcm),
                        'mimeType' => 'audio/pcm',
                    ],
                ]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
    ]);
}
