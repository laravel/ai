<?php

namespace Tests\Unit\Responses\Data;

use Laravel\Ai\Responses\Data\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function test_to_array_includes_meta_when_present(): void
    {
        $result = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: ['q' => 'test'],
            result: 'found it',
            resultId: 'res_1',
            meta: ['thinking' => 'Searching…'],
        );

        $array = $result->toArray();

        $this->assertSame('call_1', $array['id']);
        $this->assertSame('found it', $array['result']);
        $this->assertSame(['thinking' => 'Searching…'], $array['meta']);
    }

    public function test_to_array_omits_meta_when_null(): void
    {
        $result = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: ['q' => 'test'],
            result: 'found it',
        );

        $array = $result->toArray();

        $this->assertArrayNotHasKey('meta', $array);
        $this->assertArrayHasKey('result_id', $array);
    }

    public function test_json_serialize_includes_meta(): void
    {
        $result = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: [],
            result: 'ok',
            meta: ['label' => 'test'],
        );

        $json = json_decode(json_encode($result), true);

        $this->assertSame(['label' => 'test'], $json['meta']);
    }

    public function test_backward_compatible_without_meta(): void
    {
        $result = new ToolResult(
            id: 'call_1',
            name: 'search',
            arguments: ['q' => 'test'],
            result: 'found it',
            resultId: 'res_1',
        );

        $this->assertNull($result->meta);
        $this->assertSame('found it', $result->result);
    }
}
