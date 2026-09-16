<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('transcription request posts to correct endpoint', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription response text is correctly parsed', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('mistral');
});

test('transcription includes model in request', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'voxtral-mini-2602'));
});

test('transcription sends language when provided', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'language')
        && str_contains($request->body(), 'en'));
});

test('transcription sends context bias from provider options', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['context_bias' => 'Laravel,Forge,Vapor'])
        ->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'context_bias')
        && str_contains($request->body(), 'Laravel,Forge,Vapor'));
});

test('transcription sends context bias array as repeated parts', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['context_bias' => ['Laravel', 'Forge', 'Vapor']])
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return substr_count($body, 'name="context_bias"') === 3
            && str_contains($body, 'Laravel')
            && str_contains($body, 'Forge')
            && str_contains($body, 'Vapor');
    });
});

test('transcription sends bearer token', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription usage is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hello',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50);
});

test('transcription omits language and sends diarize flag when diarizing', function (): void {
    Http::fake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->diarize()
        ->generate(provider: 'mistral');

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'diarize')
            && str_contains($body, 'name="timestamp_granularities"')
            && ! str_contains($body, 'timestamp_granularities[')
            && str_contains($body, 'segment')
            && ! str_contains($body, 'language');
    });
});

test('transcription response segments are parsed when diarizing', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hello world',
        'segments' => [
            ['text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'mistral');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->text)->toBe('Hello')
        ->and($response->segments[0]->speaker)->toBe('speaker_0')
        ->and($response->segments[0]->startSeconds)->toBe(0.0)
        ->and($response->segments[1]->text)->toBe('world')
        ->and($response->segments[1]->speaker)->toBe('speaker_1');
});

test('transcription throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');
})->throws(RequestException::class);

function fakeTranscriptionResponse(string $text = 'Hello, world!')
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
