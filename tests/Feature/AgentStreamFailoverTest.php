<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Feature\Agents\AssistantAgent;
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

        Event::assertDispatched(AgentFailedOver::class);
    }

    public function test_stream_throws_last_exception_when_all_providers_fail(): void
    {
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

        return $body . "data: [DONE]\n\n";
    }
}
