<?php

namespace Tests\Unit\Gateway\Zai\Streaming;

use Laravel\Ai\Gateway\Zai\Streaming\ServerSentEventsStreamParser;
use Tests\TestCase;

class ServerSentEventsStreamParserTest extends TestCase
{
    protected ServerSentEventsStreamParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ServerSentEventsStreamParser;
    }

    public function test_parse_yields_text_deltas()
    {
        $sseData = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(2, $events);
        $this->assertEquals('text_delta', $events[0]['type']);
        $this->assertEquals('Hello', $events[0]['delta']);
        $this->assertEquals('done', $events[1]['type']);
    }

    public function test_parse_yields_reasoning_deltas()
    {
        $sseData = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking\"}}]}\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertEquals('reasoning_delta', $events[0]['type']);
        $this->assertEquals('thinking', $events[0]['delta']);
    }

    public function test_parse_yields_tool_call_deltas()
    {
        $sseData = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"name\":\"TestTool\",\"arguments\":\"{\\\"city\\\":\\\"Tokyo\\\"}\"}}]}}]}\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertEquals('tool_call_delta', $events[0]['type']);
        $this->assertEquals('TestTool', $events[0]['name']);
        $this->assertEquals('{"city":"Tokyo"}', $events[0]['arguments']);
    }

    public function test_parse_handles_done_marker_and_ends_stream()
    {
        $sseData = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: {\"choices\":[{\"delta\":{\"content\":\" World\"}}]}\n\ndata: [DONE]\n\ndata: {\"choices\":[{\"delta\":{\"content\":\"Ignored\"}}]}\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(3, $events);
        $this->assertEquals('Hello', $events[0]['delta']);
        $this->assertEquals(' World', $events[1]['delta']);
        $this->assertEquals('done', $events[2]['type']);
    }

    public function test_parse_accumulates_multiple_tool_call_deltas()
    {
        $sseData = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"name\":\"Tool1\",\"arguments\":\"\\u007b\"}}]}}]}\n\ndata: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"\\\"a\\\":1}\"}}]}}]}\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(3, $events);
        $this->assertEquals('tool_call_delta', $events[0]['type']);
        $this->assertEquals('{', $events[0]['arguments']);
        $this->assertEquals('"a":1}', $events[1]['arguments']);
    }

    public function test_parse_tracks_usage_from_chunks()
    {
        $sseData = "data: {\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{\"content\":\"Hello\"}}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(3, $events);
        $this->assertEquals('text_delta', $events[0]['type']);
        $this->assertEquals('usage', $events[1]['type']);
        $this->assertEquals(10, $events[1]['promptTokens']);
        $this->assertEquals(5, $events[1]['completionTokens']);
        $this->assertEquals('done', $events[2]['type']);
    }

    public function test_parse_handles_empty_lines()
    {
        $sseData = "\n\ndata: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(2, $events);
    }

    public function test_parse_handles_partial_lines_across_chunks()
    {
        $sseData = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\ndata: {\"choices\":[{\"delta\":{\"content\":\"World\"}}]}\ndata: [DONE]\n";

        $events = iterator_to_array($this->parser->parse($sseData));

        $this->assertCount(3, $events);
    }
}
