<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('prompt reads reasoning off the response', function (array $item, string $expected): void {
    Http::fake(['*' => $this->fakeReasonedTextResponse([$item])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning)->toBe($expected);
})->with([
    'reasoning summary' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [
            ['type' => 'summary_text', 'text' => 'Let me '],
            ['type' => 'summary_text', 'text' => 'think...'],
        ]],
        'Let me think...',
    ],
    'reasoning text' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'content' => [
            ['type' => 'reasoning_text', 'text' => 'Raw thoughts.'],
        ]],
        'Raw thoughts.',
    ],
]);

test('prompt separates each reasoning block with a blank line', function (): void {
    Http::fake(['*' => $this->fakeReasonedTextResponse([
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'First.']]],
        ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => [['type' => 'summary_text', 'text' => '   ']]],
        ['type' => 'reasoning', 'id' => 'rs_3', 'summary' => [['type' => 'summary_text', 'text' => 'Second.']]],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning)->toBe("First.\n\nSecond.");
});

test('a response without reasoning leaves the reasoning empty', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning)->toBe('');
});
