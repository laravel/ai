<?php

namespace Tests\Unit\Gateway\Zai;

use Laravel\Ai\Gateway\Zai\ZaiTool;
use Tests\TestCase;
use Tests\Unit\Tools\TestTool;

class ZaiToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.zai' => [
            'driver' => 'zai',
            'key' => 'test-key',
        ]]);
    }

    public function test_parse_arguments_handles_json_string()
    {
        $result = ZaiTool::toLaravelToolCall([
            'id' => 'call_1',
            'function' => [
                'name' => 'weather',
                'arguments' => '{"city":"Tokyo"}',
            ],
        ]);

        $this->assertEquals(['city' => 'Tokyo'], $result['arguments']);
    }

    public function test_parse_arguments_handles_array()
    {
        $result = ZaiTool::toLaravelToolCall([
            'id' => 'call_1',
            'function' => [
                'name' => 'weather',
                'arguments' => ['city' => 'Tokyo'],
            ],
        ]);

        $this->assertEquals(['city' => 'Tokyo'], $result['arguments']);
    }

    public function test_tool_converted_to_zai_format()
    {
        $tool = new TestTool('Get weather');

        $format = ZaiTool::toZaiFormat($tool);

        $this->assertEquals('function', $format['type']);
        $this->assertEquals('TestTool', $format['function']['name']);
        $this->assertEquals('Get weather', $format['function']['description']);
        $this->assertIsArray($format['function']['parameters']);
        $this->assertEquals('object', $format['function']['parameters']['type']);
    }
}
