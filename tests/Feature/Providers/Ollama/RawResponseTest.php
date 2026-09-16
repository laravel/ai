<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('ollama text responses expose the raw http response', function (): void {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'ollama',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('model'))->toBe('llama3.1:8b');
});
