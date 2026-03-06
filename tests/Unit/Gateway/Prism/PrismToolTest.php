<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Gateway\Prism\PrismTool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;

class PrismToolTest extends TestCase
{
    public function test_to_laravel_tool_call_from_array_unwraps_schema_definition(): void
    {
        $result = PrismTool::toLaravelToolCall([
            'id' => 'call_1',
            'name' => 'search',
            'arguments' => ['schema_definition' => ['query' => 'test']],
        ]);

        $this->assertInstanceOf(ToolCall::class, $result);
        $this->assertSame('call_1', $result->id);
        $this->assertSame('search', $result->name);
        $this->assertSame(['query' => 'test'], $result->arguments);
    }

    public function test_to_laravel_tool_call_from_array_uses_raw_arguments_when_schema_definition_is_missing(): void
    {
        $result = PrismTool::toLaravelToolCall([
            'id' => 'call_2',
            'name' => 'search',
            'arguments' => ['query' => 'test'],
        ]);

        $this->assertInstanceOf(ToolCall::class, $result);
        $this->assertSame(['query' => 'test'], $result->arguments);
    }

    public function test_to_laravel_tool_call_from_object_unwraps_schema_definition(): void
    {
        $prismToolCall = new PrismToolCall(
            id: 'call_3',
            name: 'search',
            arguments: ['schema_definition' => ['query' => 'test']],
            resultId: 'res_1',
            reasoningId: 'reason_1',
            reasoningSummary: ['summary'],
        );

        $result = PrismTool::toLaravelToolCall($prismToolCall);

        $this->assertInstanceOf(ToolCall::class, $result);
        $this->assertSame('call_3', $result->id);
        $this->assertSame('search', $result->name);
        $this->assertSame(['query' => 'test'], $result->arguments);
        $this->assertSame('res_1', $result->resultId);
        $this->assertSame('reason_1', $result->reasoningId);
        $this->assertSame(['summary'], $result->reasoningSummary);
    }

    public function test_to_laravel_tool_call_from_object_uses_raw_arguments_when_schema_definition_is_missing(): void
    {
        $prismToolCall = new PrismToolCall(
            id: 'call_4',
            name: 'search',
            arguments: ['query' => 'test', 'limit' => 10],
        );

        $result = PrismTool::toLaravelToolCall($prismToolCall);

        $this->assertInstanceOf(ToolCall::class, $result);
        $this->assertSame(['query' => 'test', 'limit' => 10], $result->arguments);
    }

    public function test_to_laravel_tool_result_from_array_unwraps_schema_definition(): void
    {
        $result = PrismTool::toLaravelToolResult([
            'toolCallId' => 'call_1',
            'toolName' => 'search',
            'args' => ['schema_definition' => ['query' => 'test']],
            'result' => 'found it',
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('call_1', $result->id);
        $this->assertSame('search', $result->name);
        $this->assertSame(['query' => 'test'], $result->arguments);
        $this->assertSame('found it', $result->result);
    }

    public function test_to_laravel_tool_result_from_array_uses_raw_args_when_schema_definition_is_missing(): void
    {
        $result = PrismTool::toLaravelToolResult([
            'toolCallId' => 'call_2',
            'toolName' => 'search',
            'args' => ['query' => 'test'],
            'result' => 'found it',
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(['query' => 'test'], $result->arguments);
    }

    public function test_to_laravel_tool_result_from_object_unwraps_schema_definition(): void
    {
        $prismToolResult = new PrismToolResult(
            toolCallId: 'call_3',
            toolName: 'search',
            args: ['schema_definition' => ['query' => 'test']],
            result: 'found it',
            toolCallResultId: 'res_1',
        );

        $result = PrismTool::toLaravelToolResult($prismToolResult);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('call_3', $result->id);
        $this->assertSame('search', $result->name);
        $this->assertSame(['query' => 'test'], $result->arguments);
        $this->assertSame('found it', $result->result);
        $this->assertSame('res_1', $result->resultId);
    }

    public function test_to_laravel_tool_result_from_object_uses_raw_args_when_schema_definition_is_missing(): void
    {
        $prismToolResult = new PrismToolResult(
            toolCallId: 'call_4',
            toolName: 'search',
            args: ['query' => 'test', 'limit' => 10],
            result: 'found it',
        );

        $result = PrismTool::toLaravelToolResult($prismToolResult);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(['query' => 'test', 'limit' => 10], $result->arguments);
    }
}
