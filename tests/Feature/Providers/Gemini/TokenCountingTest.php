<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TokenCountResponse;

test('gemini can count tokens before inference', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['totalTokens' => 31]),
    ]);

    $response = Ai::textProvider('gemini')->countTokens(
        messages: [new UserMessage('Hello, Gemini')],
        instructions: 'You are a scientist',
        model: 'gemini-2.5-flash',
    );

    expect($response)->toBeInstanceOf(TokenCountResponse::class)
        ->and($response->tokens)->toBe(31)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-2.5-flash');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:countTokens'
            && $request['generateContentRequest']['model'] === 'models/gemini-2.5-flash'
            && $request['generateContentRequest']['system_instruction']['parts'][0]['text'] === 'You are a scientist'
            && $request['generateContentRequest']['contents'][0]['role'] === 'user';
    });
});

test('gemini token counting defaults to the default text model', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['totalTokens' => 5]),
    ]);

    $provider = Ai::textProvider('gemini');

    $provider->countTokens(messages: [new UserMessage('Hi')]);

    Http::assertSent(
        fn ($request): bool => $request['generateContentRequest']['model'] === 'models/'.$provider->defaultTextModel()
            && ! isset($request['generateContentRequest']['system_instruction']),
    );
});
