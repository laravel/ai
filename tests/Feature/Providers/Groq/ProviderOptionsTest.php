<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in groq request body', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('reasoning', $body)
            && ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeGroqToolCallResponse(),
            fakeGroqResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'groq');

    $requests = Http::recorded(fn (Request $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode((string) $requests[1][0]->body(), true);

    expect(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});
