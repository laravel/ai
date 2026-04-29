<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\FileAttachmentToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('tool calls trigger follow up request', function () {
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

    $followUpMessages = collect(json_decode($recorded[1][0]->body(), true)['messages']);

    expect($followUpMessages->contains(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls'])))->toBeTrue()
        ->and($followUpMessages->contains(fn ($m) => $m['role'] === 'tool'))->toBeTrue();
});

test('tool result message uses tool_name field', function () {
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
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->first(fn ($m) => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg)->not->toHaveKey('tool_call_id');
});

test('max steps limits tool call depth', function () {
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

test('tool call follow up request preserves the originally requested model alias', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponseWithModel('llama3.2:2026-04-28'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
        model: 'llama3.2',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('llama3.2')
        ->and($followUpBody['model'])->toBe('llama3.2');
});

test('tool call follow up request reuses mapped attachment contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'ollama-attachment-').'.png';
    copy(__DIR__.'/../../../Fixtures/Images/red.png', $path);
    $expectedImage = base64_encode(file_get_contents($path));

    try {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FileMutatingTool'),
                $this->fakeTextResponse('done'),
            ]),
        ]);

        (new FileAttachmentToolAgent($path))->prompt(
            'Inspect this image, then use the tool.',
            attachments: [new LocalImage($path, 'image/png')],
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(2)
            ->and(file_get_contents($path))->toBe('mutated attachment contents');

        $initialUser = collect(json_decode($recorded[0][0]->body(), true)['messages'])->firstWhere('role', 'user');
        $followUpUser = collect(json_decode($recorded[1][0]->body(), true)['messages'])->firstWhere('role', 'user');

        expect($initialUser['images'][0])->toBe($expectedImage)
            ->and($followUpUser['images'][0])->toBe($expectedImage);
    } finally {
        @unlink($path);
    }
});

test('tool calls without id are executed with a generated id', function () {
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

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->first(fn ($m) => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator');
});

test('tool calls are executed even when done_reason is stop', function () {
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
