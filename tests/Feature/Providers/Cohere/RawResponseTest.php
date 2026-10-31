<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('cohere text responses expose the raw http response', function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => $this->fakeTextResponse('Hello there')]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'cohere');

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->json('id'))->toBe('08067b1d-d35b-427c-a287-d913f35bd6ca');
});
