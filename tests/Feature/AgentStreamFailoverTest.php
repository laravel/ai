<?php

namespace Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\ConversationStore;
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
use Tests\TestCase;

class AgentStreamFailoverTest extends TestCase
{
    public function test_stream_fails_over_to_next_provider_when_primary_is_rate_limited(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push($this->fakeGroqStreamBody(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(
            collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty()
        );

        $this->assertSame('Hello', $response->text);

        Event::assertDispatched(AgentFailedOver::class);

        Event::assertDispatched(AgentStreamed::class, fn (AgentStreamed $event) => $event->invocationId === $response->invocationId);
    }

    public function test_stream_throws_last_exception_when_all_providers_fail(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(status: 429);

        $this->expectException(RateLimitedException::class);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        foreach ($response as $event) {
            //
        }
    }

    public function test_stream_then_callback_is_invoked_after_failover(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push($this->fakeGroqStreamBody(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        $thenResponse = null;

        $response->then(function (StreamedAgentResponse $r) use (&$thenResponse) {
            $thenResponse = $r;
        });

        foreach ($response as $_) {
        }

        $this->assertInstanceOf(StreamedAgentResponse::class, $thenResponse);
        $this->assertSame('Hello', $thenResponse->text);
        $this->assertSame('backup', $thenResponse->meta->provider);
    }

    public function test_stream_does_not_fail_over_when_primary_succeeds(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push($this->fakeGroqStreamBody(), 200);

        $response = (new AssistantAgent)->stream(
            'Hello',
            provider: ['primary', 'backup'],
        );

        foreach ($response as $_) {
        }

        $this->assertSame('Hello', $response->text);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function test_stream_does_not_fail_over_when_primary_emits_event_then_throws(): void
    {
        Event::fake();

        $manager = app(AiManager::class);

        $manager->extend('mid_stream_failing', fn ($app, $config) => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            fn ($provider, $prompt) => new StreamableAgentResponse(
                (string) Str::uuid7(),
                function () {
                    yield (new TextDelta('m1', 'm1', 'partial', 0))->withInvocationId('inner-fail');

                    throw RateLimitedException::forProvider('mid_stream_failing');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ));

        $manager->extend('working_backup', fn ($app, $config) => new FakeStreamingProvider(
            $config,
            $app->make(Dispatcher::class),
            fn ($provider, $prompt) => new StreamableAgentResponse(
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

        $thrown = null;

        try {
            foreach ($response as $_) {
            }
        } catch (RateLimitedException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RateLimitedException::class, $thrown);

        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function test_stream_conversation_state_survives_failover(): void
    {
        $store = new InMemoryConversationStore;
        $this->app->instance(ConversationStore::class, $store);

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push($this->fakeGroqStreamBody(), 200);

        $user = (object) ['id' => 'user-1'];
        $existingConversationId = 'existing-conv-1';

        $response = (new RememberingAssistantAgent)
            ->continue($existingConversationId, $user)
            ->stream('Hello', provider: ['primary', 'backup']);

        $thenResponse = null;

        $response->then(function (StreamedAgentResponse $r) use (&$thenResponse) {
            $thenResponse = $r;
        });

        foreach ($response as $_) {
        }

        $this->assertSame($existingConversationId, $thenResponse->conversationId);
        $this->assertSame($user, $thenResponse->conversationUser);
    }

    private function fakeGroqStreamBody(): string
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
}
