<?php

use Laravel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Laravel\Ai\Responses\Data\FinishReason;

beforeEach(function (): void {
    $this->parser = new class
    {
        use ParsesTextResponses {
            extractText as public;
            extractFinishReason as public;
        }
    };
});

function responsesMessage(string ...$texts): array
{
    return [
        'type' => 'message',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => array_map(fn (string $text): array => ['type' => 'output_text', 'text' => $text, 'annotations' => []], $texts),
    ];
}

test('text is read from a single message', function (): void {
    expect($this->parser->extractText([responsesMessage('Hello')]))->toBe('Hello');
});

test('text is collected in order across every message and output_text part', function (): void {
    $output = [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Thinking']]],
        responsesMessage('First', ' part'),
        ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
        responsesMessage(' second'),
    ];

    expect($this->parser->extractText($output))->toBe('First part second');
});

test('text survives trailing function calls and extension items', function (): void {
    $output = [
        responsesMessage('Let me check.'),
        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'lookup', 'arguments' => '{}', 'status' => 'completed'],
        ['type' => 'acme:trace', 'id' => 'tr_1', 'content' => [['type' => 'output_text', 'text' => 'not model text']]],
    ];

    expect($this->parser->extractText($output))->toBe('Let me check.');
});

test('refusals and other non text parts are not reported as text', function (): void {
    $output = [[
        'type' => 'message',
        'content' => [
            ['type' => 'refusal', 'refusal' => 'No.'],
            ['type' => 'output_text', 'text' => 'Yes'],
        ],
    ]];

    expect($this->parser->extractText($output))->toBe('Yes');
});

test('text is empty without any message', function (): void {
    expect($this->parser->extractText([]))->toBe('')
        ->and($this->parser->extractText([['type' => 'function_call', 'name' => 'x', 'arguments' => '{}']]))->toBe('');
});

test('the finish reason ignores trailing extension items', function (array $output, FinishReason $expected): void {
    expect($this->parser->extractFinishReason(['status' => 'completed', 'output' => $output]))->toBe($expected);
})->with([
    'message then extension' => [[responsesMessage('Hi'), ['type' => 'acme:usage', 'status' => 'in_progress']], FinishReason::Stop],
    'function call then extension' => [[
        ['type' => 'function_call', 'status' => 'completed', 'name' => 'x', 'arguments' => '{}'],
        ['type' => 'acme:trace'],
    ], FinishReason::ToolCalls],
    'message without extension' => [[responsesMessage('Hi')], FinishReason::Stop],
    'only extension items' => [[['type' => 'acme:trace']], FinishReason::Unknown],
]);
