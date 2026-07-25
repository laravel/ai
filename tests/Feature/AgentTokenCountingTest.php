<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('agents can count tokens before inference', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['input_tokens' => 321]),
    ]);

    $response = (new AssistantAgent)->countTokens('Hello', provider: 'anthropic');

    expect($response->tokens)->toBe(321)
        ->and($response->meta->provider)->toBe('anthropic');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.anthropic.com/v1/messages/count_tokens'
            && $request['system'] === 'You are a helpful assistant that responds extremely concisely to all queries.'
            && $request['messages'][0]['role'] === 'user';
    });
});

test('agent token counting includes the agent tools', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['input_tokens' => 500]),
    ]);

    (new ToolUsingAgent)->countTokens('Give me a random number.', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => filled($request['tools']));
});
