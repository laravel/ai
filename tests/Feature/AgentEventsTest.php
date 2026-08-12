<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;

test('a synchronous prompt threads one invocation id through the prompt and its events', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    $response = (new AssistantAgent)->prompt('Hi');

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->invocationId === $response->invocationId
        && $event->prompt->invocationId === $response->invocationId);

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->invocationId === $response->invocationId);
});

test('synchronous middleware receives the invocation id the run reports', function (): void {
    AssistantAgent::fake(['Hello!']);

    $seen = null;

    $response = (new AssistantAgent)->withMiddleware([
        function (AgentPrompt $prompt, Closure $next) use (&$seen) {
            $seen = $prompt->invocationId;

            return $next($prompt);
        },
    ])->prompt('Hi');

    expect($seen)->not->toBeNull()->toBe($response->invocationId);
});

test('every failover attempt shares the run invocation id', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);

    $invocationIds = Event::dispatched(PromptingAgent::class)
        ->map(fn (array $dispatched): string => $dispatched[0]->invocationId)
        ->unique();

    expect($invocationIds)->toHaveCount(1);
});

test('a failed over run names the invocation it belongs to', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);
});
