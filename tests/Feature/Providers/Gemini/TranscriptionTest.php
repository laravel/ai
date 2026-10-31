<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

function fakeGeminiTranscriptionInteraction(string $text, array $usage): PromiseInterface
{
    return Http::response([
        'id' => 'int_transcription',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
        'usage' => $usage,
    ]);
}

function fakeGeminiTranscriptionResponse(): PromiseInterface
{
    return fakeGeminiTranscriptionInteraction('Hello world', [
        'total_input_tokens' => 10,
        'total_output_tokens' => 5,
        'total_tokens' => 15,
    ]);
}

function fakeGeminiWordTranscriptionResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'int_transcription',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [[
                'type' => 'text',
                'text' => 'Hello there. How are you?',
                'annotations' => [
                    ['type' => 'word_info', 'text' => 'Hello', 'speaker' => 'spk:0', 'start_offset' => '0.100s', 'end_offset' => '0.400s'],
                    ['type' => 'word_info', 'text' => 'there.', 'speaker' => 'spk:0', 'start_offset' => '0.400s', 'end_offset' => '1s'],
                    ['type' => 'word_info', 'text' => 'How', 'speaker' => 'spk:1', 'start_offset' => '1.200s', 'end_offset' => '1.400s'],
                    ['type' => 'word_info', 'text' => 'you?', 'speaker' => 'spk:1', 'start_offset' => '1.400s', 'end_offset' => '2s'],
                ],
            ]],
        ]],
        'usage' => ['total_input_tokens' => 55, 'total_output_tokens' => 8, 'total_tokens' => 63],
    ]);
}

function fakeGeminiDiarizedTranscriptionResponse(?array $segments = null): PromiseInterface
{
    $segments ??= [
        ['text' => 'Hello', 'start_time' => '0:00', 'end_time' => '0:02'],
        ['text' => 'world', 'start_time' => '0:02', 'end_time' => '0:04'],
    ];

    return fakeGeminiTranscriptionInteraction(
        json_encode(['transcript' => 'Hello world', 'segments' => $segments]),
        ['total_input_tokens' => 42, 'total_output_tokens' => 20, 'total_tokens' => 62],
    );
}

test('transcription request sends audio as an audio block with correct mime type', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect(sentRequest()->url())->toEndWith('/interactions')
        ->and(sentRequest()->data())->toMatchArray(['model' => 'gemini-3.7-flash'])
        ->and(sentRequest()->data()['input'][1])->toMatchArray([
            'type' => 'audio',
            'mime_type' => 'audio/mp3',
            'data' => base64_encode('fake-audio'),
        ]);
});

test('transcription request includes language in prompt when specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->language('fr')
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect(sentRequest()->data()['input'][0]['text'])->toContain('fr');
});

test('transcription response returns text with correct meta', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-3.7-flash')
        ->and($response->usage->inputTokens)->toBe(10)
        ->and($response->usage->outputTokens)->toBe(5);
});

test('transcription uses default model when none specified', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'gemini');

    expect(sentRequest()->data())->toMatchArray(['model' => 'gemini-3.5-transcribe']);
});

test('a transcribe model is configured instead of prompted', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->language('en-US')
        ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

    expect(sentRequest()->data()['input'])->toHaveCount(1)
        ->and(sentRequest()->data()['input'][0]['type'])->toBe('audio')
        ->and(sentRequest()->data()['generation_config']['transcription_config'])->toBe(['language_codes' => ['en-US']])
        ->and(sentRequest()->data())->toMatchArray(['store' => false])
        ->and(sentRequest()->data())->not->toHaveKey('response_format');
});

test('a transcribe request without a language omits the generation config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

    expect(sentRequest()->data())->not->toHaveKey('generation_config');
});

test('a diarized transcribe request asks for speaker labels and word timestamps', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiWordTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

    expect(sentRequest()->data()['generation_config']['transcription_config']['mode'])->toBe([
        'type' => 'verbatim',
        'diarization_mode' => 'speaker',
        'timestamp_granularities' => ['word'],
    ]);
});

test('a diarized transcribe response groups consecutive words into speaker segments', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiWordTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

    expect($response->text)->toBe('Hello there. How are you?')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->text)->toBe('Hello there.')
        ->and($response->segments[0]->speaker)->toBe('spk:0')
        ->and($response->segments[0]->startSeconds)->toBe(0.1)
        ->and($response->segments[0]->endSeconds)->toBe(1.0)
        ->and($response->segments[1]->text)->toBe('How you?')
        ->and($response->segments[1]->speaker)->toBe('spk:1')
        ->and($response->segments[1]->startSeconds)->toBe(1.2)
        ->and($response->segments[1]->endSeconds)->toBe(2.0)
        ->and($response->usage->inputTokens)->toBe(55);
});

test('a general purpose model still transcribes through a prompt', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect(sentRequest()->data()['input'][0]['text'])->toContain('Transcribe this audio')
        ->and(sentRequest()->data())->not->toHaveKey('generation_config');
});

test('diarized transcription request sends a json response format', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect(sentRequest()->data()['response_format'])
        ->toMatchArray(['type' => 'text', 'mime_type' => 'application/json'])
        ->and(sentRequest()->data()['response_format']['schema']['properties'])->toHaveKey('segments')
        ->and(sentRequest()->data()['input'][0]['text'])->toContain('MM:SS or HH:MM:SS');
});

test('diarized transcription response returns text and segments', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->text)->toBe('Hello')
        ->and($response->segments[0]->startSeconds)->toBe(0.0)
        ->and($response->segments[0]->endSeconds)->toBe(2.0)
        ->and($response->segments[1]->text)->toBe('world')
        ->and($response->segments[1]->startSeconds)->toBe(2.0)
        ->and($response->segments[1]->endSeconds)->toBe(4.0)
        ->and($response->usage->inputTokens)->toBe(42)
        ->and($response->usage->outputTokens)->toBe(20);
});

test('diarized transcription parses srt and hour timestamp formats', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse([
            ['text' => 'Hello', 'start_time' => '00:00:01,500', 'end_time' => '00:01:02,250'],
            ['text' => 'world', 'start_time' => '01:02:03.750', 'end_time' => '3724.25'],
        ]),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->segments[0]->startSeconds)->toBe(1.5)
        ->and($response->segments[0]->endSeconds)->toBe(62.25)
        ->and($response->segments[1]->startSeconds)->toBe(3723.75)
        ->and($response->segments[1]->endSeconds)->toBe(3724.25);
});
