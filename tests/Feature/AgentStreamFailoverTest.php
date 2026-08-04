<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\ConversationStores\InMemoryConversationStore;
use Tests\Fixtures\Providers\FakeStreamingProvider;

test('stream fails over to next provider when primary is rate limited', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(fakeGroqStreamBodyForStreamFailover(), 200);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect(collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty())->toBeTrue()
        ->and($response->text)->toBe('Hello');

    Event::assertDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(AgentFailed::class);

    Event::assertDispatched(AgentStreamed::class, fn (AgentStreamed $event): bool => $event->invocationId === $response->invocationId);
});

test('stream throws last exception when all providers fail', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);

    Event::assertDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception instanceof RateLimitedException
        && $event->prompt->provider()->name() === 'backup');
});

test('stream then callback is invoked after failover', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(fakeGroqStreamBodyForStreamFailover(), 200);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    $thenResponse = null;

    $response->then(function (StreamedAgentResponse $r) use (&$thenResponse): void {
        $thenResponse = $r;
    });

    foreach ($response as $_) {
    }

    expect($thenResponse)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($thenResponse->text)->toBe('Hello')
        ->and($thenResponse->meta->provider)->toBe('backup');
});

test('stream does not fail over when primary succeeds', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(fakeGroqStreamBodyForStreamFailover(), 200);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    foreach ($response as $_) {
    }

    expect($response->text)->toBe('Hello');

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(AgentFailed::class);
});

test('single provider stream does not dispatch failover event when rate limited', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: 'primary',
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception instanceof RateLimitedException);
});

test('stream does not fail over when primary emits event then throws', function (): void {
    Event::fake();

    $manager = app(AiManager::class);

    $manager->extend('mid_stream_failing', fn ($app, $config): FakeStreamingProvider => new FakeStreamingProvider(
        $config,
        $app->make(Dispatcher::class),
        fn ($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
            (string) Str::uuid7(),
            function () {
                yield (new TextDelta('m1', 'm1', 'partial', 0))->withInvocationId('inner-fail');

                throw RateLimitedException::forProvider('mid_stream_failing');
            },
            new Meta($provider->name(), $prompt->model),
        ),
    ));

    $manager->extend('working_backup', fn ($app, $config): FakeStreamingProvider => new FakeStreamingProvider(
        $config,
        $app->make(Dispatcher::class),
        fn ($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
            (string) Str::uuid7(),
            function () {
                yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
            },
            new Meta($provider->name(), $prompt->model),
        ),
    ));

    config([
        'ai.providers.primary' => ['driver' => 'mid_stream_failing'],
        'ai.providers.backup' => ['driver' => 'working_backup'],
    ]);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception instanceof RateLimitedException);
});

test('single provider stream dispatches agent failed when provider throws before returning a response', function (): void {
    Event::fake();

    $manager = app(AiManager::class);

    $manager->extend('immediately_failing_stream', fn ($app, $config): FakeStreamingProvider => new FakeStreamingProvider(
        $config,
        $app->make(Dispatcher::class),
        function (): StreamableAgentResponse {
            throw new InvalidArgumentException('Unable to create stream response.');
        },
    ));

    config([
        'ai.providers.primary' => ['driver' => 'immediately_failing_stream'],
    ]);

    expect(fn () => (new AssistantAgent)->stream(
        'Hello',
        provider: 'primary',
    ))->toThrow(InvalidArgumentException::class, 'Unable to create stream response.');

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->exception instanceof InvalidArgumentException
        && $event->prompt->provider()->name() === 'primary');
    Event::assertNotDispatched(AgentFailedOver::class);
});

test('stream does not fail over when primary throws non failoverable exception', function (): void {
    Event::fake();

    $manager = app(AiManager::class);
    $backupStreamed = false;

    $manager->extend('malformed_stream', fn ($app, $config): FakeStreamingProvider => new FakeStreamingProvider(
        $config,
        $app->make(Dispatcher::class),
        fn ($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
            (string) Str::uuid7(),
            function (): void {
                throw new InvalidArgumentException('Malformed stream response.');
            },
            new Meta($provider->name(), $prompt->model),
        ),
    ));

    $manager->extend('backup_after_malformed_stream', fn ($app, $config): FakeStreamingProvider => new FakeStreamingProvider(
        $config,
        $app->make(Dispatcher::class),
        function ($provider, $prompt) use (&$backupStreamed): StreamableAgentResponse {
            $backupStreamed = true;

            return new StreamableAgentResponse(
                (string) Str::uuid7(),
                function () {
                    yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
                },
                new Meta($provider->name(), $prompt->model),
            );
        },
    ));

    config([
        'ai.providers.primary' => ['driver' => 'malformed_stream'],
        'ai.providers.backup' => ['driver' => 'backup_after_malformed_stream'],
    ]);

    $response = (new AssistantAgent)->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(InvalidArgumentException::class, 'Malformed stream response.');

    expect($backupStreamed)->toBeFalse();

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception instanceof InvalidArgumentException);
});

test('stream conversation state survives failover', function (): void {
    $store = new InMemoryConversationStore;
    $this->app->instance(ConversationStore::class, $store);

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(fakeGroqStreamBodyForStreamFailover(), 200);

    $user = (object) ['id' => 'user-1'];
    $existingConversationId = 'existing-conv-1';

    $response = (new RememberingAssistantAgent)
        ->continue($existingConversationId, $user)
        ->stream('Hello', provider: ['primary', 'backup']);

    $thenResponse = null;

    $response->then(function (StreamedAgentResponse $r) use (&$thenResponse): void {
        $thenResponse = $r;
    });

    foreach ($response as $_) {
    }

    expect($thenResponse->conversationId)->toBe($existingConversationId)
        ->and($thenResponse->conversationUser)->toBe($user);
});

function fakeGroqStreamBodyForStreamFailover(): string
{
    $chunks = [
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":1,"total_tokens":6}}',
    ];

    $body = '';

    foreach ($chunks as $chunk) {
        $body .= "data: {$chunk}\n\n";
    }

    return $body."data: [DONE]\n\n";
}
