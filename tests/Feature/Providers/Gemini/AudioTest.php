<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

test('audio request includes model, prompt text, and voice name', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), 'models/gemini-2.5-flash-preview-tts:generateContent')
            && $body['contents'][0]['parts'][0]['text'] === 'Hello world'
            && $body['generationConfig']['responseModalities'] === ['AUDIO']
            && $body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore';
    });
});

test('audio request resolves default voice aliases', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');
    Audio::of('Hello world')->male()->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(fn (Request $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore');
    Http::assertSent(fn (Request $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck');
});

test('audio instructions are prepended to the prompt instead of sent in speech config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Have a wonderful day!')
        ->voice('Kore')
        ->instructions('Say cheerfully:')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $speechConfig = $body['generationConfig']['speechConfig'];

        return $body['contents'][0]['parts'][0]['text'] === "Say cheerfully:\n\nHave a wonderful day!"
            && ! isset($speechConfig['instructions'])
            && ! isset($speechConfig['voiceConfig']['instructions'])
            && ! isset($speechConfig['voiceConfig']['prebuiltVoiceConfig']['instructions']);
    });
});

test('audio response is wrapped as wav with correct meta', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse("\x01\x00\x02\x00"),
    ]);

    $response = Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    expect($response->mimeType())->toBe('audio/wav')
        ->and(substr($response->content(), 0, 4))->toBe('RIFF')
        ->and(substr($response->content(), 8, 4))->toBe('WAVE')
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-2.5-flash-preview-tts');
});

test('audio request passes custom voice name through unchanged', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('my-custom-voice')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(fn (Request $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'my-custom-voice');
});

test('audio uses default model when none specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->voice('Kore')->generate(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-3.1-flash-tts-preview:generateContent'));
});

test('nested generation config provider options are merged beneath the core config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('Kore')
        ->withProviderOptions(['generationConfig' => [
            'temperature' => 0.1,
            'responseModalities' => ['TEXT', 'IMAGE'],
            'speechConfig' => ['languageCode' => 'en-US'],
        ]])
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    Http::assertSent(function (Request $request): bool {
        $config = $request->data()['generationConfig'];

        return $config['temperature'] === 0.1
            && $config['responseModalities'] === ['AUDIO']
            && $config['speechConfig']['languageCode'] === 'en-US'
            && $config['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore';
    });
});

function fakeGeminiAudioResponse(string $pcm = "\x00\x00"): PromiseInterface
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
