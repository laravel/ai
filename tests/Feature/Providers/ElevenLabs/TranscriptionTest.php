<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
    ]]);
});

test('transcription request posts to speech-to-text with model, language, and diarize flag', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))
        ->language('en')
        ->generate(provider: 'eleven', model: 'scribe_v2');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'model_id' && $part['contents'] === 'scribe_v2')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'en')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'diarize' && $part['contents'] === 'false');
    });
});

test('transcription request sends diarize=true when enabled', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse(diarized: true)]);

    Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    Http::assertSent(
        fn (Request $request) => collect($request->data())
            ->contains(fn ($part) => $part['name'] === 'diarize' && $part['contents'] === 'true')
    );
});

test('transcription request sends xi-api-key header', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    Http::assertSent(fn (Request $request) => $request->hasHeader('xi-api-key', 'test-key'));
});

test('transcription response returns plain text with no segments when diarize is off', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse()]);

    $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('eleven')
        ->and($response->meta->model)->toBe('scribe_v2');
});

test('transcription response builds segments from words when diarize is on', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse(diarized: true)]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->text)->toBe('Hello')
        ->and($response->segments[0]->speaker)->toBe('speaker_0')
        ->and($response->segments[0]->startSeconds)->toBe(0.0)
        ->and($response->segments[0]->endSeconds)->toBe(0.5)
        ->and($response->segments[1]->text)->toBe('world')
        ->and($response->segments[1]->speaker)->toBe('speaker_1');
});

test('transcription filters out non-word segments (e.g. spacing)', function () {
    Http::fake(['*' => Http::response([
        'text' => 'Hello world',
        'words' => [
            ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['type' => 'spacing', 'text' => ' ', 'start' => 0.5, 'end' => 0.6],
            ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_0', 'start' => 0.6, 'end' => 1.0],
        ],
    ])]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->pluck('text')->all())->toBe(['Hello', 'world']);
});

test('transcription uses default model when none specified', function () {
    Http::fake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven');

    Http::assertSent(
        fn (Request $request) => collect($request->data())
            ->contains(fn ($part) => $part['name'] === 'model_id' && $part['contents'] === 'scribe_v2')
    );
});

test('transcription throws when the API returns an error', function () {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');
})->throws(ProviderRequestException::class);

function fakeElevenTranscriptionResponse(bool $diarized = false)
{
    $body = ['text' => 'Hello world'];

    if ($diarized) {
        $body['words'] = [
            ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
        ];
    }

    return Http::response($body);
}
