<?php

namespace Tests\Unit\Streaming\Events;

use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use PHPUnit\Framework\TestCase;

class ToolResultEventTest extends TestCase
{
    public function test_vercel_protocol_includes_meta_when_present(): void
    {
        $toolResult = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: [],
            result: '{"data":"ok"}',
            meta: ['thinking' => 'Searching…'],
        );

        $event = new ToolResultEvent('evt_1', $toolResult, true, null, time());

        $vercel = $event->toVercelProtocolArray();

        $this->assertSame('tool-output-available', $vercel['type']);
        $this->assertSame('call_1', $vercel['toolCallId']);
        $this->assertSame('{"data":"ok"}', $vercel['output']);
        $this->assertSame(['thinking' => 'Searching…'], $vercel['meta']);
    }

    public function test_vercel_protocol_omits_meta_when_null(): void
    {
        $toolResult = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: [],
            result: '{"data":"ok"}',
        );

        $event = new ToolResultEvent('evt_1', $toolResult, true, null, time());

        $vercel = $event->toVercelProtocolArray();

        $this->assertArrayNotHasKey('meta', $vercel);
        $this->assertSame('tool-output-available', $vercel['type']);
    }

    public function test_to_array_includes_meta_when_present(): void
    {
        $toolResult = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: [],
            result: 'data',
            meta: ['label' => 'test'],
        );

        $event = new ToolResultEvent('evt_1', $toolResult, true, null, time());
        $event->withInvocationId('inv_1');

        $array = $event->toArray();

        $this->assertSame('tool_result', $array['type']);
        $this->assertSame(['label' => 'test'], $array['meta']);
    }

    public function test_to_array_omits_meta_when_null(): void
    {
        $toolResult = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: [],
            result: 'data',
        );

        $event = new ToolResultEvent('evt_1', $toolResult, true, null, time());
        $event->withInvocationId('inv_1');

        $array = $event->toArray();

        $this->assertArrayNotHasKey('meta', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertArrayHasKey('successful', $array);
    }
}
