<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('prompt reads the thinking chunks off the response', function (): void {
    Http::fake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => [
            ['type' => 'text', 'text' => 'Let me '],
            ['type' => 'text', 'text' => 'think...'],
        ]],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'mistral');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('prompt separates each thinking chunk with a blank line', function (): void {
    Http::fake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'First.']]],
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => '   ']]],
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Second.']]],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'mistral')->reasoning)->toBe("First.\n\nSecond.");
});

test('a tool call follow up replays the assistant content with its thinking chunks', function (): void {
    Http::fake(['*' => Http::sequence([
        $this->fakeReasonedToolCallResponse('I should call the tool.'),
        $this->fakeTextResponse('The number is 72019'),
    ])]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'mistral');

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    $assistant = collect($followUp['messages'])->firstWhere('role', 'assistant');

    expect($assistant['content'])->toBe([
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'I should call the tool.']]],
    ]);
});

test('streaming emits reasoning events before the text', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me ']]]]], 'finish_reason' => null]]],
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'think...']]]]], 'finish_reason' => null]]],
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});

test('a streamed tool call follow up replays the thinking it accumulated', function (): void {
    $chunk = fn (array $delta, ?string $finishReason = null, ?array $usage = null): array => array_filter([
        'id' => 'c1',
        'model' => 'magistral-medium-latest',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        'usage' => $usage,
    ]);

    Http::fake(['*' => Http::sequence([
        Http::response(
            body: $this->ssePayload([
                $chunk(['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'I should ']]]]]),
                $chunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'call the tool.']]]]]),
                $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}']]]]),
                $chunk([], 'tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
        Http::response(
            body: $this->ssePayload([
                $chunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $chunk([], 'stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ])]);

    foreach (agent(tools: [new FixedNumberGenerator])->stream('Generate a random number', provider: 'mistral') as $event) {
        //
    }

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->firstWhere('role', 'assistant')['content'])->toBe([
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'I should call the tool.']]],
    ]);
});

test('a delta carrying both the last thinking and the first text closes one reasoning block', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me ']]]]], 'finish_reason' => null]]],
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'think...']]],
                ['type' => 'text', 'text' => 'Hello'],
            ]], 'finish_reason' => null]]],
            ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect(collect($events)->filter(fn ($event): bool => $event instanceof ReasoningStart))->toHaveCount(1)
        ->and($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});
