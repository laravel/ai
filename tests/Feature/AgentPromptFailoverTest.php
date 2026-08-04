<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

test('prompt dispatches agent failed when all providers are exhausted', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    expect(fn () => (new AssistantAgent)->prompt(
        'Hello',
        provider: ['primary', 'backup'],
    ))->toThrow(RateLimitedException::class);

    $failure = null;

    Event::assertDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentFailed::class, function (AgentFailed $event) use (&$failure): bool {
        $failure = $event;

        return $event->exception instanceof RateLimitedException
            && $event->prompt->provider()->name() === 'backup'
            && $event->prompt->invocationId === $event->invocationId
            && $event->usage === null;
    });

    $promptingInvocationIds = Event::dispatched(PromptingAgent::class)
        ->map(fn (array $arguments): string => $arguments[0]->invocationId)
        ->unique()
        ->values()
        ->all();

    expect($promptingInvocationIds)->toBe([$failure->invocationId]);

    Event::assertNotDispatched(AgentPrompted::class);
});

test('prompt dispatches agent failed for a non failoverable exception', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 400);

    expect(fn () => (new AssistantAgent)->prompt(
        'Hello',
        provider: 'primary',
    ))->toThrow(RequestException::class);

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->exception instanceof RequestException);
    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(AgentPrompted::class);
});

test('prompt does not dispatch agent failed when failover succeeds', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);

    $response = (new AssistantAgent)->prompt(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect($response->text)->toBe('Hello');

    Event::assertDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(AgentFailed::class);
    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->invocationId === $response->invocationId);
    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->invocationId === $response->invocationId);
});

test('prompt does not dispatch agent failed when no provider can be resolved', function (): void {
    Event::fake();

    expect(fn () => (new AssistantAgent)->prompt(
        'Hello',
        provider: [],
    ))->toThrow(RuntimeException::class, 'No AI providers were configured.');

    Event::assertNotDispatched(AgentFailed::class);
});
