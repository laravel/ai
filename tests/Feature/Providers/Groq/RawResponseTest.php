<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('groq text responses expose the raw http response', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('id'))->toBe('chatcmpl-123');
});
