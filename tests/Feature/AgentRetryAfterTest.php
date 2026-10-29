<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Events\AgentFailedOver;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Event::fake();
    Sleep::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();
});

test('a rate limited provider is retried after its Retry-After delay before failing over', function (): void {
    Http::fakeSequence()
        ->push(status: 429, headers: ['Retry-After' => '3'])
        ->pushResponse(fakeGroqResponse('Hello after waiting.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello after waiting.')
        ->and($response->meta->provider)->toBe('primary');

    Sleep::assertSequence([Sleep::for(3)->seconds()]);
    Event::assertNotDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

test('an overloaded provider is retried with a backoff before failing over', function (): void {
    Http::fakeSequence()
        ->push(status: 503)
        ->pushResponse(fakeGroqResponse('Hello after the backoff.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello after the backoff.')
        ->and($response->meta->provider)->toBe('primary');

    Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
    Event::assertNotDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

test('a provider still overloaded after two retries fails over', function (): void {
    Http::fakeSequence()
        ->push(status: 503)
        ->push(status: 503)
        ->push(status: 503)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.')
        ->and($response->meta->provider)->toBe('backup');

    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1)->second()]);
    Event::assertDispatched(AgentFailedOver::class);
    Http::assertSentCount(4);
});
