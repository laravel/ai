<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('deepseek text responses expose the raw http response', function (): void {
    Http::fake([
        'api.deepseek.com/*' => fakeDeepSeekResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'deepseek',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('id'))->toBe('chatcmpl-deepseek-123');
});
