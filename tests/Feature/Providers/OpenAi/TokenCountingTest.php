<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TokenCountResponse;

test('openai can count tokens before inference', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['object' => 'response.input_tokens', 'input_tokens' => 42]),
    ]);

    $response = Ai::textProvider('openai')->countTokens(
        messages: [new UserMessage('Hello, GPT')],
        instructions: 'You are a scientist',
        model: 'gpt-5.2',
    );

    expect($response)->toBeInstanceOf(TokenCountResponse::class)
        ->and($response->tokens)->toBe(42)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-5.2');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.com/v1/responses/input_tokens'
            && $request['model'] === 'gpt-5.2'
            && filled($request['input'])
            && ! isset($request['max_output_tokens']);
    });
});

test('openai token counting defaults to the default text model', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['object' => 'response.input_tokens', 'input_tokens' => 7]),
    ]);

    $provider = Ai::textProvider('openai');

    $provider->countTokens(messages: [new UserMessage('Hi')]);

    Http::assertSent(fn ($request): bool => $request['model'] === $provider->defaultTextModel());
});
