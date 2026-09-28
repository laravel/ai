<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('prompt reads the thinking blocks off the response', function (): void {
    Http::fake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => 'Let me think...'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'cohere');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('prompt separates each thinking block with a blank line', function (): void {
    Http::fake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => 'First.'],
        ['type' => 'thinking', 'thinking' => '   '],
        ['type' => 'thinking', 'thinking' => 'Second.'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'cohere')->reasoning)->toBe("First.\n\nSecond.");
});
