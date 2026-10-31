<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('prompt reads thinking blocks off the response', function (): void {
    Http::fake(['*' => $this->fakeThinkingResponse([
        ['type' => 'thinking', 'thinking' => 'Let me think...', 'signature' => 'sig-1'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning)->toBe('Let me think...');
});

test('prompt separates each thinking block with a blank line', function (): void {
    Http::fake(['*' => $this->fakeThinkingResponse([
        ['type' => 'thinking', 'thinking' => 'First.', 'signature' => 'sig-1'],
        ['type' => 'thinking', 'thinking' => '   ', 'signature' => 'sig-2'],
        ['type' => 'thinking', 'thinking' => 'Second.', 'signature' => 'sig-3'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning)->toBe("First.\n\nSecond.");
});

test('redacted thinking contributes no reasoning text', function (): void {
    Http::fake(['*' => $this->fakeThinkingResponse([
        ['type' => 'redacted_thinking', 'data' => 'encrypted-blob'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning)->toBe('');
});
