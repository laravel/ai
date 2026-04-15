<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\CachedSystemPromptAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

test('provider options are included in anthropic request body', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && $body['thinking']['budget_tokens'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return ! isset($request->data()['thinking']);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
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

test('system_prompt_cache_type formats system prompt as cached content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new CachedSystemPromptAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return is_array($body['system'])
            && $body['system'][0]['type'] === 'text'
            && str_contains($body['system'][0]['text'], 'helpful')
            && $body['system'][0]['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('system_prompt_cache_type is not included as a top level key in request body', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new CachedSystemPromptAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return ! isset($request->data()['system_prompt_cache_type']);
    });
});

test('system prompt remains a string when agent does not set system_prompt_cache_type', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return is_string($request->data()['system']);
    });
});
