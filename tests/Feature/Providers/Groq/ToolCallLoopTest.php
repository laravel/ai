<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\FileAttachmentToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'groq',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeUniqueGroqToolCallResponse(),
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'groq',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool call follow up request preserves the originally requested model alias', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponseWithModel('llama-3.3-70b-versatile-2026-04-28'),
            fakeGroqResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'groq',
        model: 'llama-3.3-70b-versatile',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('llama-3.3-70b-versatile')
        ->and($followUpBody['model'])->toBe('llama-3.3-70b-versatile');
});

test('tool call follow up request reuses mapped attachment contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'groq-attachment-').'.png';
    copy(__DIR__.'/../../../Fixtures/Images/red.png', $path);
    $expectedUrl = 'data:image/png;base64,'.base64_encode(file_get_contents($path));

    try {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                fakeGroqToolCallResponseForTool('FileMutatingTool'),
                fakeGroqResponse('done'),
            ]),
        ]);

        (new FileAttachmentToolAgent($path))->prompt(
            'Inspect this image, then use the tool.',
            attachments: [new LocalImage($path, 'image/png')],
            provider: 'groq',
        );

        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(2)
            ->and(file_get_contents($path))->toBe('mutated attachment contents');

        $initialUser = collect(json_decode($recorded[0][0]->body(), true)['messages'])->firstWhere('role', 'user');
        $followUpUser = collect(json_decode($recorded[1][0]->body(), true)['messages'])->firstWhere('role', 'user');

        expect(collect($initialUser['content'])->firstWhere('type', 'image_url')['image_url']['url'])->toBe($expectedUrl)
            ->and(collect($followUpUser['content'])->firstWhere('type', 'image_url')['image_url']['url'])->toBe($expectedUrl);
    } finally {
        @unlink($path);
    }
});

function fakeUniqueGroqToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function fakeGroqToolCallResponseForTool(string $toolName): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => $toolName,
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function fakeGroqToolCallResponseWithModel(string $model): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
