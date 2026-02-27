<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Gateway\Prism\PrismStreamEvent;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\ValueObjects\Usage;

class PrismStreamEventTest extends TestCase
{
    public function test_thinking_complete_event_maps_to_reasoning_end(): void
    {
        $event = new ThinkingCompleteEvent(
            id: 'event-1',
            timestamp: 1234567890,
            reasoningId: 'reasoning-1',
            summary: ['text' => 'Summary of reasoning'],
        );

        $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

        $this->assertInstanceOf(ReasoningEnd::class, $result);
        $this->assertEquals('event-1', $result->id);
        $this->assertEquals('reasoning-1', $result->reasoningId);
        $this->assertEquals(1234567890, $result->timestamp);
        $this->assertEquals(['text' => 'Summary of reasoning'], $result->summary);
    }

    public function test_thinking_complete_event_handles_null_summary(): void
    {
        $event = new ThinkingCompleteEvent(
            id: 'event-2',
            timestamp: 1234567890,
            reasoningId: 'reasoning-2',
        );

        $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

        $this->assertInstanceOf(ReasoningEnd::class, $result);
        $this->assertNull($result->summary);
    }

    public function test_stream_end_event_handles_null_usage(): void
    {
        $event = new StreamEndEvent(
            id: 'event-3',
            timestamp: 1234567890,
            finishReason: FinishReason::Stop,
            usage: null,
        );

        $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openrouter', 'anthropic/claude-sonnet');

        $this->assertInstanceOf(StreamEnd::class, $result);
        $this->assertEquals(0, $result->usage->promptTokens);
        $this->assertEquals(0, $result->usage->completionTokens);
    }

    public function test_stream_end_event_maps_usage(): void
    {
        $event = new StreamEndEvent(
            id: 'event-4',
            timestamp: 1234567890,
            finishReason: FinishReason::Stop,
            usage: new Usage(promptTokens: 100, completionTokens: 50),
        );

        $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openrouter', 'anthropic/claude-sonnet');

        $this->assertInstanceOf(StreamEnd::class, $result);
        $this->assertEquals(100, $result->usage->promptTokens);
        $this->assertEquals(50, $result->usage->completionTokens);
    }

    public function test_error_event_maps_correctly(): void
    {
        $event = new \Prism\Prism\Streaming\Events\ErrorEvent(
            id: 'event-5',
            timestamp: 1234567890,
            errorType: 'server_error',
            message: 'Something went wrong',
            recoverable: false,
            metadata: ['foo' => 'bar'],
        );

        $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

        $this->assertInstanceOf(\Laravel\Ai\Streaming\Events\Error::class, $result);
        /** @var \Laravel\Ai\Streaming\Events\Error $result */
        $this->assertEquals('event-5', $result->id);
        $this->assertEquals('server_error', $result->type);
        $this->assertEquals('Something went wrong', $result->message);
        $this->assertFalse($result->recoverable);
        $this->assertEquals(1234567890, $result->timestamp);
        $this->assertEquals(['foo' => 'bar'], $result->metadata);
    }
}
