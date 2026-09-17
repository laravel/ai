<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('prompt reads thought parts off the response', function (): void {
    Http::fake(['*' => $this->fakeThinkingResponse([
        ['text' => 'Let me ', 'thought' => true],
        ['text' => 'think...', 'thought' => true],
        ['text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'gemini')->reasoning)->toBe('Let me think...');
});

test('prompt separates thought runs interrupted by an answer with a blank line', function (): void {
    Http::fake(['*' => $this->fakeThinkingResponse([
        ['text' => 'First.', 'thought' => true],
        ['text' => 'Partial answer. '],
        ['text' => 'Second.', 'thought' => true],
        ['text' => 'Rest of the answer.'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'gemini')->reasoning)->toBe("First.\n\nSecond.");
});

test('a response without thought parts leaves the reasoning empty', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'gemini')->reasoning)->toBe('');
});
