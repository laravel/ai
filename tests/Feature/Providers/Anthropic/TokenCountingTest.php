<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TokenCountResponse;

test('anthropic can count tokens before inference', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['input_tokens' => 2095]),
    ]);

    $response = Ai::textProvider('anthropic')->countTokens(
        messages: [new UserMessage('Hello, Claude')],
        instructions: 'You are a scientist',
    );

    expect($response)->toBeInstanceOf(TokenCountResponse::class)
        ->and($response->tokens)->toBe(2095)
        ->and($response->meta->provider)->toBe('anthropic');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.anthropic.com/v1/messages/count_tokens'
            && $request['system'] === 'You are a scientist'
            && $request['messages'][0]['role'] === 'user'
            && ! isset($request['max_tokens']);
    });
});

test('anthropic token counting uses the given model and omits empty instructions', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['input_tokens' => 12]),
    ]);

    $response = Ai::textProvider('anthropic')->countTokens(
        messages: [new UserMessage('Hi')],
        model: 'claude-opus-4-8',
    );

    expect($response->tokens)->toBe(12)
        ->and($response->meta->model)->toBe('claude-opus-4-8');

    Http::assertSent(function ($request): bool {
        return $request['model'] === 'claude-opus-4-8'
            && ! isset($request['system']);
    });
});

test('anthropic token counting defaults to the default text model', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['input_tokens' => 5]),
    ]);

    $provider = Ai::textProvider('anthropic');

    $provider->countTokens(messages: [new UserMessage('Hi')]);

    Http::assertSent(fn ($request): bool => $request['model'] === $provider->defaultTextModel());
});
