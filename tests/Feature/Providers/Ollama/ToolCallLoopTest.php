<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('tool calls trigger follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpMessages = collect(json_decode((string) $recorded[1][0]->body(), true)['messages']);

    expect($followUpMessages->contains(fn ($m): bool => $m['role'] === 'assistant' && isset($m['tool_calls'])))->toBeTrue()
        ->and($followUpMessages->contains(fn ($m): bool => $m['role'] === 'tool'))->toBeTrue();
});

test('tool result message uses tool_name field', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();
    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->first(fn ($m): bool => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg)->not->toHaveKey('tool_call_id');
});

test('max steps limits tool call depth', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool calls without id are executed with a generated id', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        // No "id" field — some Ollama models omit it.
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => (object) [],
                        ],
                    ]],
                ],
                'done_reason' => 'tool_calls',
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 5,
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->first(fn ($m): bool => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator');
});

test('tool calls are executed even when done_reason is stop', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => (object) [],
                        ],
                    ]],
                ],
                // Real Ollama responses can report "stop" even when tool_calls
                // are populated.
                'done_reason' => 'stop',
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 5,
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2)
        ->and($response->text)->toBe('The number is 72019');
});

test('multi step tool loop returns accumulated response shape', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt(
        'Generate numbers',
        provider: 'ollama',
    );

    expect((string) $response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

test('unregistered tool call throws NoSuchToolException', function (): void {
    Http::fake([
        '*' => Http::response([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_123',
                    'function' => [
                        'name' => 'UnregisteredTool',
                        'arguments' => (object) [],
                    ],
                ]],
            ],
            'done_reason' => 'tool_calls',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]),
    ]);

    expect(fn (): AgentResponse => (new MultiStepToolAgent)->prompt(
        'Generate a number',
        provider: 'ollama',
    ))->toThrow(NoSuchToolException::class);
});

function fakeUniqueOllamaToolCallResponse(): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_'.uniqid(),
                'function' => [
                    'name' => 'FixedNumberGenerator',
                    'arguments' => (object) [],
                ],
            ]],
        ],
        'done_reason' => 'tool_calls',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ]);
}
