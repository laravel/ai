<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('transcription sends prompt from provider options', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'Laravel Forge and Vapor'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
        && str_contains($request->body(), 'prompt')
        && str_contains($request->body(), 'Laravel Forge and Vapor'));
});

test('transcription throws when prompt provider option is used with diarized models', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'Laravel Forge and Vapor'])
        ->diarize()
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');
})->throws(LogicException::class, 'OpenAI does not support the `prompt` option for diarized transcriptions.');

test('transcription request posts to correct endpoint', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription includes model in request', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'gpt-4o-transcribe'));
});

test('transcription strips diarize suffix from model when diarize is off', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'gpt-4o-transcribe')
        && ! str_contains($request->body(), 'gpt-4o-transcribe-diarize'));
});

test('transcription response text is correctly parsed', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('openai');
});

test('transcription usage is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50);
});

test('transcription duration falls back to the billed seconds when no duration is reported', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'type' => 'duration',
            'seconds' => 3,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->usage->durationSeconds)->toBe(3.0);
});

test('diarized transcription reports the duration', function (): void {
    Http::fake(['*' => Http::response([
        'task' => 'transcribe',
        'text' => 'Hello',
        'duration' => 2.11,
        'segments' => [],
        'usage' => [
            'type' => 'duration',
            'seconds' => 3,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

    expect($response->usage->durationSeconds)->toBe(2.11);
});

test('transcription duration is zero when the provider does not report one', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->usage->durationSeconds)->toBe(0.0);
});

test('transcription sends language when provided', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'language')
        && str_contains($request->body(), 'en'));
});

test('transcription request sends bearer token', function (): void {
    Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded.',
            ],
        ], 503),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid file format',
            ],
        ], 400),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(RequestException::class);

function fakeOpenAiTranscriptionResponse(string $text = 'Hello, world!')
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'input_tokens' => 10,
            'total_tokens' => 15,
        ],
    ]);
}
