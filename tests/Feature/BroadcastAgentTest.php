<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class BroadcastAgentTest extends TestCase
{
    public function test_then_callback_receives_streamed_agent_response(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $received = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function ($response) use (&$received) {
            $received = $response;
        });

        $job->handle();

        $this->assertNotNull($received, 'then() callback was never invoked');
        $this->assertInstanceOf(StreamedAgentResponse::class, $received);
        $this->assertSame('Hello world', $received->text);
    }

    public function test_multiple_then_callbacks_all_receive_streamed_agent_response(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $receivedA = null;
        $receivedB = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function ($response) use (&$receivedA) {
            $receivedA = $response;
        });

        $job->then(function ($response) use (&$receivedB) {
            $receivedB = $response;
        });

        $job->handle();

        $this->assertInstanceOf(StreamedAgentResponse::class, $receivedA);
        $this->assertInstanceOf(StreamedAgentResponse::class, $receivedB);
    }

    public function test_streamed_response_passed_to_then_is_fully_resolved(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $received = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function ($response) use (&$received) {
            $received = $response;
        });

        $job->handle();

        $this->assertNotNull($received, 'then() callback was never invoked');
        $this->assertInstanceOf(StreamedAgentResponse::class, $received);
        $this->assertNotEmpty($received->events);
        $this->assertSame('Hello world', $received->text);
    }
}
