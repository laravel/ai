<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;

function fakeMiniMaxBaseUrlAudioResponse(): PromiseInterface
{
    return Http::response([
        'data' => ['audio' => bin2hex('fake-audio-bytes'), 'status' => 2],
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
    ]);
}

test('minimax audio requests fall back to the global base url', function (): void {
    config(['ai.providers.minimax' => array_diff_key(
        [...config('ai.providers.minimax'), 'key' => 'test-key'],
        ['url' => null],
    )]);

    Http::fake(['*' => fakeMiniMaxBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.minimax.io/v1/t2a_v2');
});

test('minimax audio requests use the configured regional base url', function (): void {
    config(['ai.providers.minimax' => [
        ...config('ai.providers.minimax'),
        'key' => 'test-key',
        'url' => 'https://api.minimaxi.com/v1',
    ]]);

    Http::fake(['*' => fakeMiniMaxBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.minimaxi.com/v1/t2a_v2');
});

test('minimax audio requests use the configured base url', function (): void {
    config(['ai.providers.minimax' => [
        ...config('ai.providers.minimax'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeMiniMaxBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/t2a_v2');
});
