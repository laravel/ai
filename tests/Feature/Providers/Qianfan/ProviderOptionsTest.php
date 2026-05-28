<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.qianfan' => [
        ...config('ai.providers.qianfan'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in qianfan request body', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    agent()->prompt('Hello', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake(['*' => $this->fakeQianfanToolCallResponse()]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'qianfan');

    $requests = Http::recorded(fn (Request $request) => true);

    expect($requests)->toHaveCount(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});
