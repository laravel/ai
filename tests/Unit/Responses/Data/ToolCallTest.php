<?php

namespace Tests\Unit\Responses\Data;

use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolCallTest extends TestCase
{
    public function test_to_array_includes_meta_when_present(): void
    {
        $call = new ToolCall(
            id: 'call_1',
            name: 'search',
            arguments: ['q' => 'test'],
            meta: ['thinking' => 'Looking…'],
        );

        $array = $call->toArray();

        $this->assertSame(['thinking' => 'Looking…'], $array['meta']);
    }

    public function test_to_array_omits_meta_when_null(): void
    {
        $call = new ToolCall(
            id: 'call_1',
            name: 'search',
            arguments: ['q' => 'test'],
        );

        $array = $call->toArray();

        $this->assertArrayNotHasKey('meta', $array);
        $this->assertArrayHasKey('result_id', $array);
        $this->assertArrayHasKey('reasoning_id', $array);
        $this->assertArrayHasKey('reasoning_summary', $array);
    }

    public function test_backward_compatible_without_meta(): void
    {
        $call = new ToolCall(
            id: 'call_1',
            name: 'search',
            arguments: [],
            resultId: 'res_1',
            reasoningId: 'r_1',
            reasoningSummary: ['thinking'],
        );

        $this->assertNull($call->meta);
        $this->assertSame('call_1', $call->id);
    }
}
