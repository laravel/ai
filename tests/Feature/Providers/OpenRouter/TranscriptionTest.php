<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
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
]);

test('transcription wraps raw pcm audio in a wav header and sends as wav format', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    $pcm = str_repeat("\x01\x00", 1000);

    Transcription::fromBase64(base64_encode($pcm), 'audio/pcm')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) use ($pcm) {
        $body = json_decode($request->body(), true);

        $sent = base64_decode($body['input_audio']['data']);

        return $body['input_audio']['format'] === 'wav'
            && str_starts_with($sent, 'RIFF')
            && substr($sent, 8, 4) === 'WAVE'
            && str_ends_with($sent, $pcm);
    });
});

test('transcription throws invalid argument exception for unsupported mime type', function () {
    Http::fake();

    expect(fn () => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/x-aiff')
        ->generate(provider: 'openrouter'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported audio MIME type [audio/x-aiff]');

    Http::assertNothingSent();
});

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
        return json_decode($request->body(), true)['model'] === 'openai/whisper-1';
    });
});

test('transcription response text is correctly parsed', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('openai/whisper-1');
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
        ->and($response->usage->completionTokens)->toBe(50);
});

test('transcription request sends bearer token', function () {
    Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Rate limit exceeded',
                'code' => 429,
            ],
        ], 429),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Service overloaded',
                'code' => 503,
            ],
        ], 503),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Invalid audio format',
                'code' => 400,
            ],
        ], 400),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(ProviderRequestException::class);
