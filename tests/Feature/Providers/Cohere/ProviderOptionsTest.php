<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in cohere request body', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'k') === 40
            && data_get($body, 'safety_mode') === 'CONTEXTUAL';
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('k', $body)
            && ! array_key_exists('safety_mode', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'cohere');

    $requests = Http::recorded();

    expect($requests)->toHaveCount(2)
        ->and(data_get(json_decode((string) $requests[1][0]->body(), true), 'k'))->toBe(40);
});
