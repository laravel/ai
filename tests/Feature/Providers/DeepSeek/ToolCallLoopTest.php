<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\FileAttachmentToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::sequence([
            fakeUniqueDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'deepseek',
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
        'api.deepseek.com/*' => Http::sequence([
            fakeUniqueDeepSeekToolCallResponse(),
            fakeUniqueDeepSeekToolCallResponse(),
            fakeUniqueDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'deepseek',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool call follow up request preserves the originally requested model alias', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::sequence([
            fakeDeepSeekToolCallResponseWithModel('deepseek-chat-2026-04-28'),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'deepseek',
        model: 'deepseek-chat',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('deepseek-chat')
        ->and($followUpBody['model'])->toBe('deepseek-chat');
});

test('tool call follow up request reuses mapped attachment contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'deepseek-attachment-').'.png';
    copy(__DIR__.'/../../../Fixtures/Images/red.png', $path);
    $expectedUrl = 'data:image/png;base64,'.base64_encode(file_get_contents($path));

    try {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                fakeDeepSeekToolCallResponseForTool('FileMutatingTool'),
                fakeDeepSeekResponse('done'),
            ]),
        ]);

        (new FileAttachmentToolAgent($path))->prompt(
            'Inspect this image, then use the tool.',
            attachments: [new LocalImage($path, 'image/png')],
            provider: 'deepseek',
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

function fakeUniqueDeepSeekToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
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

function fakeDeepSeekToolCallResponseForTool(string $toolName): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
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

function fakeDeepSeekToolCallResponseWithModel(string $model): PromiseInterface
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
