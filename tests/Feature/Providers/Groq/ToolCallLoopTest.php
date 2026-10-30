<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FileReturningTool;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function (): void {
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

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

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

test('a file a tool returns is named in its tool result instead of sent as bytes', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponse('FileReturningTool'),
            fakeGroqResponse('It is a chart.'),
        ]),
    ]);

    $png = "\x89PNG\r\n\x1a\n";

    agent(tools: [new FileReturningTool(Image::fromBase64(base64_encode($png), 'image/png')->as('chart.png'))])
        ->prompt('What does the tool return?', provider: 'groq');

    $body = (string) Http::recorded()[1][0]->body();

    expect(collect(json_decode($body, true)['messages'])->last())->toBe([
        'role' => 'tool',
        'tool_call_id' => 'call_123',
        'content' => "chart.png\n[The image chart.png was left out because it could not be sent to this provider.]",
    ])
        ->and($body)->not->toContain(base64_encode($png))
        ->and($body)->not->toContain($png);
});

test('max steps limits tool call depth', function (): void {
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

test('multi step tool loop returns accumulated response shape', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt(
        'Generate numbers',
        provider: 'groq',
    );

    expect((string) $response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->inputTokens)->toBe(21)
        ->and($response->usage->outputTokens)->toBe(11);
});

test('unknown tool call throws', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'id' => 'chatcmpl-missing',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_missing',
                        'type' => 'function',
                        'function' => [
                            'name' => 'MissingTool',
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
        ]),
    ]);

    expect(fn (): AgentResponse => (new MultiStepToolAgent)->prompt(
        'Generate numbers',
        provider: 'groq',
    ))->toThrow(NoSuchToolException::class);
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
