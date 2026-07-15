<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

test('provider options are included in anthropic request body', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && $body['thinking']['budget_tokens'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(fn ($request): bool => ! isset($request->data()['thinking']));
});

test('provider options are persisted in tool call follow up requests', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ProviderOptionsWithToolsAgent)->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstBody = $recorded[0][0]->data();
    expect($firstBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);

    $secondBody = $recorded[1][0]->data();
    expect($secondBody)->toHaveKey('thinking')
        ->and($secondBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);
});
