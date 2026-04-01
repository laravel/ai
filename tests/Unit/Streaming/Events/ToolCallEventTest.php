<?php

namespace Tests\Unit\Streaming\Events;

use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use PHPUnit\Framework\TestCase;

class ToolCallEventTest extends TestCase
{
    public function test_vercel_protocol_includes_meta_when_present(): void
    {
        $toolCall = new ToolCall(
            id: 'call_1',
            name: 'ReadPolicies',
            arguments: ['id' => ''],
            meta: ['thinking' => 'Reading policies…'],
        );

        $event = new ToolCallEvent('evt_1', $toolCall, time());

        $vercel = $event->toVercelProtocolArray();

        $this->assertSame('tool-input-available', $vercel['type']);
        $this->assertSame('call_1', $vercel['toolCallId']);
        $this->assertSame('ReadPolicies', $vercel['toolName']);
        $this->assertSame(['id' => ''], $vercel['input']);
        $this->assertSame(['thinking' => 'Reading policies…'], $vercel['meta']);
    }

    public function test_vercel_protocol_omits_meta_when_null(): void
    {
        $toolCall = new ToolCall(
            id: 'call_1',
            name: 'ReadPolicies',
            arguments: ['id' => ''],
        );

        $event = new ToolCallEvent('evt_1', $toolCall, time());

        $vercel = $event->toVercelProtocolArray();

        $this->assertArrayNotHasKey('meta', $vercel);
        $this->assertSame('tool-input-available', $vercel['type']);
    }

    public function test_to_array_includes_meta_when_present(): void
    {
        $toolCall = new ToolCall(
            id: 'call_1',
            name: 'ReadPolicies',
            arguments: [],
            meta: ['label' => 'test'],
        );

        $event = new ToolCallEvent('evt_1', $toolCall, time());
        $event->withInvocationId('inv_1');

        $array = $event->toArray();

        $this->assertSame('tool_call', $array['type']);
        $this->assertSame(['label' => 'test'], $array['meta']);
    }

    public function test_to_array_omits_meta_when_null(): void
    {
        $toolCall = new ToolCall(
            id: 'call_1',
            name: 'ReadPolicies',
            arguments: [],
        );

        $event = new ToolCallEvent('evt_1', $toolCall, time());
        $event->withInvocationId('inv_1');

        $array = $event->toArray();

        $this->assertArrayNotHasKey('meta', $array);
        $this->assertArrayHasKey('reasoning_id', $array);
    }
}
