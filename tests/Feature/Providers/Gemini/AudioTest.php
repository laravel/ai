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

function fakeGeminiAudioResponse(string $pcm = "\x00\x00", array $usage = [], array $extra = []): PromiseInterface
{
    return Http::response(array_filter([
        'id' => 'int_audio',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [array_merge([
                'type' => 'audio',
                'data' => base64_encode($pcm),
                'mime_type' => 'audio/pcm',
            ], $extra)],
        ]],
        'usage' => $usage ?: null,
    ]));
}

test('audio request includes model, prompt text, and voice name', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect(sentRequest()->url())->toEndWith('/interactions')
        ->and(sentRequest()->data())->toMatchArray([
            'model' => 'gemini-3.1-flash-tts-preview',
            'input' => 'Hello world',
            'response_format' => ['type' => 'audio'],
            'store' => false,
        ])
        ->and(sentRequest()->data()['generation_config']['speech_config'])->toBe([['voice' => 'Kore']]);
});

test('audio request resolves default voice aliases', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');
    Audio::of('Hello world')->male()->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    Http::assertSent(fn (Request $request): bool => $request->data()['generation_config']['speech_config'][0]['voice'] === 'Kore');
    Http::assertSent(fn (Request $request): bool => $request->data()['generation_config']['speech_config'][0]['voice'] === 'Puck');
});

test('audio instructions are prepended to the prompt instead of sent in speech config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Have a wonderful day!')
        ->voice('Kore')
        ->instructions('Say cheerfully:')
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect(sentRequest()->data()['input'])->toBe("Say cheerfully:\n\nHave a wonderful day!")
        ->and(sentRequest()->data()['generation_config']['speech_config'][0])->not->toHaveKey('instructions');
});

test('audio response is wrapped as wav with correct meta', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse("\x01\x00\x02\x00"),
    ]);

    $response = Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect($response->mimeType())->toBe('audio/wav')
        ->and(substr($response->content(), 0, 4))->toBe('RIFF')
        ->and(substr($response->content(), 8, 4))->toBe('WAVE')
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-3.1-flash-tts-preview');
});

test('audio response honours the sample rate and channels gemini reports', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(
            "\x01\x00\x02\x00",
            extra: ['sample_rate' => 48000, 'channels' => 2],
        ),
    ]);

    $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    $header = $response->content();

    expect(unpack('V', substr($header, 24, 4))[1])->toBe(48000)
        ->and(unpack('v', substr($header, 22, 2))[1])->toBe(2);
});

test('audio request passes custom voice name through unchanged', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('my-custom-voice')
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect(sentRequest()->data()['generation_config']['speech_config'][0])->toMatchArray(['voice' => 'my-custom-voice']);
});

test('audio uses default model when none specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->voice('Kore')->generate(provider: 'gemini');

    expect(sentRequest()->data())->toMatchArray(['model' => 'gemini-3.1-flash-tts-preview']);
});

test('nested generation config provider options are merged beneath the core config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('Kore')
        ->withProviderOptions(['generation_config' => [
            'temperature' => 0.1,
            'speech_config' => [['language_code' => 'en-US']],
        ]])
        ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect(sentRequest()->data()['generation_config'])->toMatchArray(['temperature' => 0.1])
        ->and(sentRequest()->data()['generation_config']['speech_config'][0])->toMatchArray([
            'language_code' => 'en-US',
            'voice' => 'Kore',
        ]);
});

test('audio response reports the usage returned by gemini', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse("\x00\x00", [
            'total_input_tokens' => 9,
            'total_output_tokens' => 480,
            'total_tokens' => 489,
        ]),
    ]);

    $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect($response->usage->inputTokens)->toBe(9)
        ->and($response->usage->outputTokens)->toBe(480);
});

test('audio response defaults to zero usage when gemini omits the usage', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

    expect($response->usage->totalTokens())->toBe(0);
});

test('a response without an audio block fails loudly', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'id' => 'int_audio',
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'No audio.']]]],
        ]),
    ]);

    Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');
})->throws(RuntimeException::class, 'No audio data received from Gemini API.');
