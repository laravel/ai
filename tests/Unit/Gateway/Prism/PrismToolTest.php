<?php

use Laravel\Ai\Gateway\Prism\PrismTool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;

test('to laravel tool call from array unwraps schema definition', function () {
    $result = PrismTool::toLaravelToolCall([
        'id' => 'call_1',
        'name' => 'search',
        'arguments' => ['schema_definition' => ['query' => 'test']],
    ]);

    expect($result)->toBeInstanceOf(ToolCall::class);
    expect($result->id)->toBe('call_1');
    expect($result->name)->toBe('search');
    expect($result->arguments)->toBe(['query' => 'test']);
});

test('to laravel tool call from array uses raw arguments when schema definition is missing', function () {
    $result = PrismTool::toLaravelToolCall([
        'id' => 'call_2',
        'name' => 'search',
        'arguments' => ['query' => 'test'],
    ]);

    expect($result)->toBeInstanceOf(ToolCall::class);
    expect($result->arguments)->toBe(['query' => 'test']);
});

test('to laravel tool call from object unwraps schema definition', function () {
    $prismToolCall = new PrismToolCall(
        id: 'call_3',
        name: 'search',
        arguments: ['schema_definition' => ['query' => 'test']],
        resultId: 'res_1',
        reasoningId: 'reason_1',
        reasoningSummary: ['summary'],
    );

    $result = PrismTool::toLaravelToolCall($prismToolCall);

    expect($result)->toBeInstanceOf(ToolCall::class);
    expect($result->id)->toBe('call_3');
    expect($result->name)->toBe('search');
    expect($result->arguments)->toBe(['query' => 'test']);
    expect($result->resultId)->toBe('res_1');
    expect($result->reasoningId)->toBe('reason_1');
    expect($result->reasoningSummary)->toBe(['summary']);
});

test('to laravel tool call from object uses raw arguments when schema definition is missing', function () {
    $prismToolCall = new PrismToolCall(
        id: 'call_4',
        name: 'search',
        arguments: ['query' => 'test', 'limit' => 10],
    );

    $result = PrismTool::toLaravelToolCall($prismToolCall);

    expect($result)->toBeInstanceOf(ToolCall::class);
    expect($result->arguments)->toBe(['query' => 'test', 'limit' => 10]);
});

test('to laravel tool result from array unwraps schema definition', function () {
    $result = PrismTool::toLaravelToolResult([
        'toolCallId' => 'call_1',
        'toolName' => 'search',
        'args' => ['schema_definition' => ['query' => 'test']],
        'result' => 'found it',
    ]);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->id)->toBe('call_1');
    expect($result->name)->toBe('search');
    expect($result->arguments)->toBe(['query' => 'test']);
    expect($result->result)->toBe('found it');
});

test('to laravel tool result from array uses raw args when schema definition is missing', function () {
    $result = PrismTool::toLaravelToolResult([
        'toolCallId' => 'call_2',
        'toolName' => 'search',
        'args' => ['query' => 'test'],
        'result' => 'found it',
    ]);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->arguments)->toBe(['query' => 'test']);
});

test('to laravel tool result from object unwraps schema definition', function () {
    $prismToolResult = new PrismToolResult(
        toolCallId: 'call_3',
        toolName: 'search',
        args: ['schema_definition' => ['query' => 'test']],
        result: 'found it',
        toolCallResultId: 'res_1',
    );

    $result = PrismTool::toLaravelToolResult($prismToolResult);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->id)->toBe('call_3');
    expect($result->name)->toBe('search');
    expect($result->arguments)->toBe(['query' => 'test']);
    expect($result->result)->toBe('found it');
    expect($result->resultId)->toBe('res_1');
});

test('to laravel tool result from object uses raw args when schema definition is missing', function () {
    $prismToolResult = new PrismToolResult(
        toolCallId: 'call_4',
        toolName: 'search',
        args: ['query' => 'test', 'limit' => 10],
        result: 'found it',
    );

    $result = PrismTool::toLaravelToolResult($prismToolResult);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->arguments)->toBe(['query' => 'test', 'limit' => 10]);
});
