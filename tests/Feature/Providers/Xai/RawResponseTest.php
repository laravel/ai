<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('xai text responses expose the raw http response', function (): void {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'xai',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('id'))->toBe('resp_123');
});
