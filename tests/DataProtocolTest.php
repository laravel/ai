<?php

namespace Tests;

use Illuminate\Support\Facades\Event;
use IteratorAggregate;
use Laravel\Ai\Responses\Concerns\CanStreamUsingDataProtocol;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage as UsageData;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Prism\Prism\Enums\FinishReason;
use Traversable;

class TestStreamResponse implements IteratorAggregate
{
    use CanStreamUsingDataProtocol;

    public function __construct(public array $events) {}

    public function getIterator(): Traversable
    {
        foreach ($this->events as $event) {
            yield $event;
        }
    }

    public function toResponse()
    {
        return $this->toDataProtocolResponse();
    }
}

class DataProtocolTest extends TestCase
{
    public function test_it_streams_data_protocol_events()
    {
        $events = [
            new StreamStart('msg_1', 'openai', 'gpt-4', 1234567890),
            new TextDelta('msg_1', 'msg_1', 'Hello', 1234567891),
            new TextDelta('msg_1', 'msg_1', ' World', 1234567892),
            new StreamEnd('msg_1', 'stop', new UsageData(10, 20), 1234567893),
        ];

        $response = (new TestStreamResponse($events))->toResponse();

        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;
            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertMatchesRegularExpression('/' .
            'data: \{.*"type":"start".*"messageId":"msg_1".*\}.*' .
            'data: \{.*"type":"text-delta".*"id":"msg_1".*"delta":"Hello".*\}.*' .
            'data: \{.*"type":"text-delta".*"id":"msg_1".*"delta":" World".*\}.*' .
            'data: \{.*"type":"finish".*"finishReason":"[Ss]top".*\}.*' .
            'data: \[DONE\]' .
            '/s', $output);
    }

    public function test_it_streams_tool_calls_and_results()
    {
        $toolCallData = new ToolCallData('call_1', 'calculator', ['a' => 1, 'b' => 2]);
        $toolResultData = new ToolResultData('call_1', 'calculator', ['a' => 1, 'b' => 2], 3, 'res_1');

        $events = [
            new StreamStart('msg_1', 'openai', 'gpt-4', 1234567890),
            new ToolCall('msg_1', $toolCallData, 1234567891),
            new ToolResult('msg_1', $toolResultData, true, null, 1234567892),
            new StreamEnd('msg_1', 'stop', new UsageData(10, 20), 1234567893),
        ];

        $response = (new TestStreamResponse($events))->toResponse();

        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;
            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertMatchesRegularExpression('/' .
            'data: \{.*"type":"start".*\}.*' .
            'data: \{.*"type":"tool-input-available".*"toolCallId":"call_1".*"toolName":"calculator".*\}.*' .
            'data: \{.*"type":"tool-output-available".*"toolCallId":"call_1".*"output":3.*\}.*' .
            'data: \[DONE\]' .
            '/s', $output);
    }
}
