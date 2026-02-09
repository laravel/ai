<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Gateway\Prism\PrismStreamEvent;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;

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
}
