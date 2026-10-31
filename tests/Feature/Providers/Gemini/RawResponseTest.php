<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('gemini text responses expose the raw http response', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('steps.0.content.0.text'))->toBe('Hello there');
});
