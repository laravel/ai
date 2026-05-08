<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.nvidia' => [
        ...config('ai.providers.nvidia'),
        'key' => 'test-key',
    ]]);
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        '*' => fakeNvidiaResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('agent provider options are not leaked to nvidia when keyed for another driver', function () {
    Http::fake([
        '*' => fakeNvidiaResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});
