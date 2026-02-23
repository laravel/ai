<?php

namespace Tests\Unit\Streaming\Events;

use Laravel\Ai\Streaming\Events\ReasoningDelta;
use PHPUnit\Framework\TestCase;

class ReasoningDeltaTest extends TestCase
{
    public function test_to_vercel_protocol_array_returns_reasoning_delta_type(): void
    {
        $event = new ReasoningDelta(
            id: 'evt_1',
            reasoningId: 'reasoning_1',
            delta: 'thinking...',
            timestamp: 1234567890,
        );

        $this->assertSame([
            'type' => 'reasoning-delta',
            'id' => 'reasoning_1',
            'delta' => 'thinking...',
        ], $event->toVercelProtocolArray());
    }

    public function test_to_array_uses_reasoning_delta_snake_case_type(): void
    {
        $event = (new ReasoningDelta(
            id: 'evt_1',
            reasoningId: 'reasoning_1',
            delta: 'thinking...',
            timestamp: 1234567890,
        ))->withInvocationId('invocation_1');

        $this->assertSame('reasoning_delta', $event->toArray()['type']);
    }
}
