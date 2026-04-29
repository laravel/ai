<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\FileAttachmentToolAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    $response = agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    expect($response->text)->toBe('The number is 72019');

    $requests = Http::recorded(fn (Request $r) => true);
    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);
    $messages = $followUpBody['messages'];

    $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('tool_calls');

    $toolMsg = collect($messages)->firstWhere('role', 'tool');
    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_call_id'])->toBe('call_123');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('Done'),
        ]),
    ]);

    $agent = new #[MaxSteps(3)] class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new FixedNumberGenerator];
        }
    };

    $agent->prompt('Keep calling tools', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeLessThanOrEqual(3);
});

test('tool call follow up request preserves the originally requested model alias', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponseWithModel('openai/gpt-4.1-mini-2025-04-14'),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt(
        'Give me a number',
        provider: 'openrouter',
        model: 'openai/gpt-4.1-mini',
    );

    $recorded = Http::recorded(fn (Request $r) => true);

    expect($recorded)->toHaveCount(2);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('openai/gpt-4.1-mini')
        ->and($followUpBody['model'])->toBe('openai/gpt-4.1-mini');
});

test('tool call follow up request reuses mapped attachment contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'openrouter-attachment-').'.png';
    copy(__DIR__.'/../../../Fixtures/Images/red.png', $path);
    $expectedUrl = 'data:image/png;base64,'.base64_encode(file_get_contents($path));

    try {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponseForTool('FileMutatingTool'),
                fakeOpenRouterResponse('done'),
            ]),
        ]);

        (new FileAttachmentToolAgent($path))->prompt(
            'Inspect this image, then use the tool.',
            attachments: [new LocalImage($path, 'image/png')],
            provider: 'openrouter',
        );

        $recorded = Http::recorded(fn (Request $r) => true);

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

function fakeOpenRouterToolCallResponseForTool(string $toolName)
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
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

function fakeOpenRouterToolCallResponseWithModel(string $model)
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
